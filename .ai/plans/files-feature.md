# Files: фасад `File` и диск `sconcur_local` на фиче Files

## Цель

Дать приложению файловые операции, которые не держат PHP-поток воркера, на фиче
`SConcur\Features\Files\Files` (sconcur 0.14.0), в двух местах:

- **A** — биндинг `files` (фасад `File`, `Illuminate\Filesystem\Filesystem`): выборочно, по
  флагу;
- **B** — драйвер диска `sconcur_local` для `Storage`: адаптер Flysystem поверх фичи.

## Исходные факты

- По бенчмаркам библиотеки фича выигрывает там, где байты не пересекают границу PHP и
  расширения: `copy` (2x синхронно, 9x конкурентно), `list`/`walk` с метаданными (2.2x),
  `hashFile` (1.3x конкурентно), `readChunks` (пик 4 MB вместо 132 MB). `read`/`write`
  большого файла медленнее нативных — покупается свободный поток, а не скорость. На
  маленьком файле с тёплым кэшем фича медленнее всегда.
- Биндинг `files` использует сам фреймворк на горячем пути каждого запроса:
  `FileViewFinder` (`exists`), `View\Compilers\Compiler` (`exists`, `lastModified` ×2),
  `Translation\FileLoader` (`exists`, `get`, `getRequire`). Эти вызовы через фичу стали бы
  приостановкой вместо микросекунд.
- У фичи нет: блокировок (`flock`), `symlink`/`readlink`, `glob(3)`, `access(2)`,
  fopen-хэндла. Дедлайн по умолчанию — 30 с на вызов (`Files::DEFAULT_TIMEOUT_MS`), у
  нативных функций дедлайна нет.
- Вне корутины фича работает синхронно, но только добавляет переход через границу —
  выигрыша нет.
- Много мест делает `new Filesystem()` напрямую, мимо контейнера — на них подмена
  биндинга не распространяется, и это нормально.

## Общее правило обоих вариантов

Вызов идёт в фичу, только если:

1. текущий код выполняется в корутине (`Fiber::getCurrent() !== null`, как в
   `Support\CooperativeSleep`) и расширение загружено;
2. метод из списка тех, где фича выигрывает или не проигрывает, и его контракт
   повторяется точно.

Иначе — родительская реализация без изменений. Решение «корутина или нет» — в одном
месте: `src/Support/Coroutine::isActive()` (новый класс; `CooperativeSleep` переводится на
него).

Контракт — нативный: те же возвращаемые значения и те же исключения фреймворка/Flysystem.
Исключения фичи переводятся на месте, наружу `SConcur\Exceptions\Files\*` не выходят.
Проверяется parity-тестами по образцу `PhpRedisParityTest`: каждый случай прогоняется
через родителя и через подкласс на одном дереве во временном каталоге, с `assertSame` по
ответу, по исключению и по итоговому состоянию диска (содержимое, права).

## A. Биндинг `files`

`src/Filesystem/Filesystem.php` — `SConcur\Laravel\Filesystem\Filesystem extends
Illuminate\Filesystem\Filesystem`. Переопределяет только:

| Метод | В фиче | Детали контракта |
|---|---|---|
| `copy($path, $target)` | `Files::copy` (`Replace`) | `bool`; `false` вместо исключения, как `@copy` |
| `move($path, $target)` | `Files::move` | `bool`, как `@rename` |
| `hash($path, $algorithm)` | `Files::hashFile` для `md5`, `sha1`, `sha256`, `sha512` | прочие алгоритмы (`xxh128` у Blade и т.д.) — родитель; `false` на отсутствующий файл, как `hash_file` |
| `hasSameHash($first, $second)` | два `hashFile` конкурентно (`WaitGroup`) | алгоритм — `md5`, как у родителя |
| `replace($path, $content, $mode)` | `Files::writeAtomic` | `realpath` симлинка как у родителя; права: `$mode` или `0777 - umask()`, как у родителя (не 0 «сохранить») |

Не трогаем: всё, что с `lock` (`get`/`put`/`append` с `$lock`, `sharedGet`), `exists`,
`isFile`, `lastModified`, `size` и прочие метаданные (горячий путь фреймворка),
`getRequire`/`requireOnce` (это `include`), `link`/`relativeLink`, `glob`, `isReadable`/
`isWritable`, `files`/`allFiles`/`directories` (контракт — `SplFileInfo` Symfony Finder),
`get`/`put` без блокировки (выигрыша в скорости нет; можно добавить позже отдельным флагом).

Включение: `sconcur.filesystem.files` (`SCONCUR_FILESYSTEM_FILES`, по умолчанию `false`).
Провайдер при `true` делает `$this->app->extend('files', ...)` — `extend`, а не
`singleton`, потому что `files` мог быть уже разрезолвлен до регистрации пакета.

Дедлайн: `sconcur.filesystem.timeout_ms`, по умолчанию `0` (нет дедлайна, как у нативных
функций).

## B. Диск `sconcur_local`

```php
// config/filesystems.php
'uploads' => [
    'driver' => 'sconcur_local',
    'root'   => storage_path('app/uploads'),
    // всё остальное — как у 'local': visibility, permissions, directory_visibility,
    // links, serve, url, throw, report
    'timeout_ms' => 0,
],
```

- `src/Filesystem/SconcurLocalFilesystemAdapter.php` — `extends
  League\Flysystem\Local\LocalFilesystemAdapter` (класс не `final`). Свой `PathPrefixer` и
  `VisibilityConverter` (у родителя они `private`), построенные из тех же аргументов.
- Регистрация: `FilesystemManager::extend('sconcur_local', ...)` в `callAfterResolving`,
  собирает то же, что `FilesystemManager::createLocalDriver()`, но с нашим адаптером,
  и отдаёт `Illuminate\Filesystem\LocalFilesystemAdapter` — чтобы `path()`, `url()`,
  `temporaryUrl()`, `serve` работали как у `local`.
- Вне корутины каждый метод — родитель, то есть `sconcur_local` ведёт себя ровно как
  `local`.

| Метод адаптера | В фиче | Детали |
|---|---|---|
| `write` | `Files::writeAtomic` | вместо `file_put_contents` + `LOCK_EX`: читатель видит старое или новое содержимое, никогда половину — сильнее, чем `flock`, который работает только между теми, кто сам берёт блокировку. Права нового файла — из `VisibilityConverter` (у родителя `0666 & ~umask`, затем `setVisibility`, если visibility задана) — повторить точно |
| `writeStream` | `Files::openWriter` + чтение ресурса кусками | если у ресурса есть локальный `uri` обычного файла — `Files::copy` без чтения в PHP |
| `read` | `Files::read`, `maxReadBytes: 0` | нативный `read` лимита не имеет |
| `readStream` | родитель | контракт — PHP-ресурс; из фичи его не получить без копии в `php://temp`, а `fopen` дёшев — читает потребитель |
| `copy` | `Files::copy` | сохранение visibility — как у родителя (`retain_visibility`) |
| `move` | `Files::move` | |
| `delete` | `Files::delete(missingOk: true)` | |
| `deleteDirectory` | `Files::removeDirectory(recursive: true)` | родитель удаляет и симлинки внутри, не следуя по ним — проверить совпадение в parity |
| `createDirectory` | `Files::makeDirectory(recursive: true)` | права — из visibility |
| `fileExists`, `directoryExists`, `fileSize`, `lastModified`, `visibility` | `Files::stat` | один переход вместо нескольких syscall |
| `listContents` | `Files::list` / `Files::walk`, `withMetadata: true` | `links`: `DISALLOW_LINKS` → `SymbolicLinkEncountered`, `SKIP_LINKS` → пропуск, как у родителя; порядок записей у родителя не определён — сравнивать множеством |
| `checksum` | `Files::hashFile` для md5/sha1/sha256/sha512, иначе родитель | `checksum_algo` из `Config` |
| `mimeType`, `setVisibility` | родитель | finfo и `chmod` — локальные дешёвые вызовы |

Исключения фичи → исключения Flysystem (`UnableToWriteFile`, `UnableToReadFile`,
`UnableToCopyFile`, `UnableToMoveFile`, `UnableToDeleteFile`, `UnableToCreateDirectory`,
`UnableToRetrieveMetadata`, `UnableToProvideChecksum`), дальше Laravel сам решает по
`throw`/`report`.

## Тесты

- `tests/Feature/Filesystem/FilesystemParityTest.php` — A: каждый переопределённый метод
  через родителя и подкласс, внутри корутины (`WaitGroup`), на временном дереве:
  ответы, исключения, содержимое и права файлов. Плюс: вне корутины вызов не доходит до
  фичи; `xxh128` идёт к родителю.
- `tests/Feature/Filesystem/SconcurLocalParityTest.php` — B: тот же подход, диск
  `local` против диска `sconcur_local` с одинаковой конфигурацией (visibility,
  permissions, links), через `Storage::disk()`.
- Конкурентность: N `copy` / `write` в `WaitGroup` — результат верный, воркер не
  замерзает (`BaseRedisTestCase::longestStallMs()`-подобный замер тикером).
- Провайдер: флаг `false` — `files` стоковый; `true` — наш подкласс; диск
  `sconcur_local` собирается.

## Документация (пары en/ru)

- новый `docs/files.md` / `docs/files.ru.md`: оба варианта, где выигрыш, что не
  переведено и почему, конфиг, отличия (атомарная запись вместо `LOCK_EX`);
- `README.md` / `README.ru.md` — строка в таблице документов;
- `docs/configuration.md` / `.ru.md` — `SCONCUR_FILESYSTEM_FILES`, `timeout_ms`;
- `docs/layout.md` / `.ru.md`, `.ai/README.md` — `src/Filesystem/`.

## Демо

Необязательно, решить отдельно: эндпоинт, который копирует/хеширует большой файл N раз
конкурентно и последовательно, как `/api/concurrent`.

## Порядок работ

1. `Support\Coroutine`, перевод `CooperativeSleep` на него.
2. A: подкласс, флаг, провайдер, parity-тест.
3. B: адаптер, драйвер, parity-тест.
4. Документация, `make check`.

## Решения мейнтейнера

1. Запись диска ведёт себя как штатный диск: `file_put_contents` с `LOCK_EX`. У фичи нет
   `flock`, поэтому `write`/`writeStream` идут в фичу только при `'lock' => 0` — и тогда
   так же, как пишет `local` с `'lock' => 0`: на месте, без блокировки. `writeAtomic` для
   диска не используется.
2. Дедлайн по умолчанию — `0`.
3. Флаг A по умолчанию выключен.

## Итог реализации и отличия от плана

- Ошибки: вместо перевода исключений фичи в исключения фреймворка — `FilesFeatureCall`:
  `FilesException` передаёт вызов родителю, и тот падает ровно так, как падал
  (предупреждение → `ErrorException`, `false`, `UnableTo*` с нативным сообщением).
  Неудачная операция выполняется дважды — зато parity по ошибкам точная, без подделки
  сообщений. `FileStoppedException` и `FileTimeoutException` не передаются.
- A: `hasSameHash` остался родителю — он считает `xxh128`, которого у фичи нет. `copy`
  на самого себя и пути с обёртками потоков (`://`) — родителю. Флаг читается при резолве
  `files` (в замыкании `extend`), а не в `register()`.
- B: `listContents` остался родителю — записи `Files::list`/`walk` не несут прав, а
  каждой записи нужна visibility; `createDirectory` — тоже родителю (`mkdir` дёшев, а
  `makeDirectory` фичи не делает `chmod` существующему каталогу).
- B: `writeStream` локального файла, прочитанного с начала (`putFile()` загрузки), — через
  `Files::copy`; иной поток — кусками через `openWriter`; начав читать поток, передать
  вызов родителю нельзя — ошибка там собственный `UnableToWriteFile`.
- B: `serve`, `read-only`, `prefix` отклоняются при сборке диска: маршрут раздачи
  фреймворк регистрирует только для драйвера `local`, а адаптеры `read-only`/`prefix` —
  в необязательных пакетах Flysystem, которых нет в зависимостях.
- `longestStallMs()` вынесен из `BaseRedisTestCase` в `tests/Feature/MeasuresStallsTrait`.
- Документация — `docs/filesystem.md` / `.ru.md` (а не `files.md`: так называется
  документ библиотеки о самой фиче).
- Демо не трогали.

## Правки по ревью

- `copy`/`move` на тот же файл через симлинк или жёсткую ссылку: фича обрезала файл, который
  читает. Теперь `LocalPaths::isSameFile()` (устройство + inode) отдаёт такой вызов родителю.
- Stringable-пути (`SplFileInfo`, `UploadedFile`) в `File::copy/move/hash/replace` давали
  `TypeError` под `strict_types` — приводятся к строке.
- `writeStream`: ярлык «поток локального файла → `Files::copy`» убран — он не видел фильтров
  потока и оставлял поток в начале. Поток всегда читается кусками; `openWriter` идёт через
  `FilesFeatureCall` с откатом на родителя.
- Перенос между файловыми системами: после `Files::move` назначение получает режим
  источника (`LocalPaths::crossDeviceMode()`), как у `rename()`.
- `delete`/`deleteDirectory` симлинка — родителю (висячий симлинк и симлинк на каталог).
- `File::replace` с содержимым не строкой — родителю.
- Пул задач: сигнал под мастером — `EXIT_RESTART` (75), иначе watchdog оставлял пул лежать
  при `on-failure`. Без мастера — по-прежнему 0.
- `docs/queue*.md` — про watchdog и нативно блокирующие джобы.
- Тесты: `FeaturePathTest` + `NativeFileCallSpy` доказывают, что работу сделала фича;
  кейсы на симлинки, жёсткие ссылки, фильтр потока, Stringable-пути; `CrossDeviceMoveTest`
  (`/dev/shm`); замер простоя на `md5` с абсолютным порогом; тест, что `MasterStartCommand`
  передаёт обработчик watchdog мастеру.

## Правки по второму ревью

- Перенос между файловыми системами — целиком нативно (`LocalPaths::crossesDevices()`):
  `rename()` копирует через путь назначения (в файл за симлинком, с режимом и владельцем
  источника), а фича заменяет назначение; `chmod` после `Files::move` убран.
- Проверки «тот же файл», «между ФС», `is_link` — внутри ветки фичи: вне корутины они
  стоили лишних `stat`.
- `writeStream`: `@fread`, ошибка чтения — `UnableToWriteFile` с `error_get_last()`, как у
  родителя; писатель закрывается, прочитанное остаётся записанным.
- Пул задач: формулировка — `stop`/`reload`/retire мастера тоже шлют `SIGTERM`, но код
  выхода там не читается; под мастером пул останавливает `sconcur:tasks:stop`, `kill` его
  перезапускает.
- Тесты: `CrossDeviceMoveTest` — назначение отсутствует/файл/симлинк, фасад и диск, со
  шпионом на нативный `rename`; `FeaturePathTest` — копирование и перенос на существующий
  файл, `mkdir` запрещён; `TaskPoolSignalTest` — сигнал во время `stop`.

## Кэш stat

- Нативные `unlink`/`rename`/`rmdir` сбрасывают кэш `stat` PHP, фича — нет: после
  `delete`/`deleteDirectory` диска `is_file`/`is_dir` отвечали по устаревшей записи.
  Теперь каждое изменение на фиче заканчивается `LocalPaths::forgetStats()`
  (`clearstatcache(true)`) — и там, где нативный вызов кэш не трогает (решение мейнтейнера:
  сбрасывать после всех изменений). `StatCacheTest`; без правки падают `delete` и
  `deleteDirectory`.
- Оставшиеся отличия от нативного кода описаны в `docs/filesystem*.md`, раздел
  «Differences from the native code».
