# Шаг 1. Правила, стиль, анализаторы, composer

## 1.1 Правила для агентов

Схема та же, что в `sconcur-php` и `slogger.back`: единый источник в `.ai/README.md`,
корневые `AGENTS.md` и `CLAUDE.md` — короткие указатели на него.

**`AGENTS.md`, `CLAUDE.md`** — по 5–8 строк, текст берём из `sconcur-php` (там формат
короче и опрятнее, чем в `slogger.back`), меняя ссылки на актуальные файлы.

**`.ai/README.md`** — новый файл, разделы:

- *Project* — что это за пакет: Laravel-интеграция SConcur, три рантайма (HTTP-сервер,
  пул консьюмеров RabbitMQ, пул периодических задач), coroutine-scoped приложение.
  Карта `src/` — брать из раздела «Структура» в `README.md`, ничего не выдумывая.
- *Further reading* — `README.md`, `docs/*.ru.md`, `.ai/plans/`.
- *Plans* — правило `sconcur-php`: детальные планы в `.ai/plans/`, **пишутся на
  русском**, из `README.md` и `docs/` на них не ссылаться.
- *Build & run* — таблица команд `make` (из [03-makefile.md](03-makefile.md)).
- *Architecture* — что где лежит и почему: `Foundation/AsyncApplication`, адаптеры
  (`Config`, `Events`, `Routing`, `Translation`, `View`), `Database/Mysql`,
  `Queue/Rabbitmq`, `Tasks`, `Http`, `Servers`, `Console`.
- *Tests* — `tests/` + `workbench/`, как запускать (см. [05](05-tests-workbench.md)).
- *Demo* — что такое `demo/`, почему это не workbench, как поднять.
- *Code style* — переносим из `.ai/README.md` пакета `sconcur-php` целиком (PHP 8.4,
  PSR-12, `readonly` DTO, без `final`, свойства `protected`, запрет ведущего `\` в
  именах классов, единицы измерения в именах, форматирование вызовов, именованные
  аргументы), плюс правила `slogger.back` про трейты с постфиксом `Trait` и запрет
  глобальных helper-функций.
- *Language* — английский везде, кроме `*.ru.md` и `.ai/plans/`.
- *Exceptions* — правило `sconcur-php`: никаких `@throws`, не бросать встроенные
  исключения напрямую. **Проверить по коду**: в `src/` пакета нет каталога
  `Exceptions/`; если пакет действительно бросает встроенные — записать в правила
  фактическое положение дел и завести отдельный план, а не выдавать желаемое за
  действительное.
- *Workflow rules* — ждать явного одобрения перед коммитом/пушем, не создавать веток,
  предлагать план перед реализацией, после изменений PHP гонять `make check`.
- *Answering & code references* — путь от корня + номер строки.
- *Commit & PR guidelines* — короткий императивный заголовок, трейлер
  `Co-Authored-By: <агент> <email>` с реальной версией модели.

## 1.2 Стиль

**`.editorconfig`** — копия из `sconcur-php` (файлы в трёх проектах побайтово
одинаковы, md5 `483074134d36b872788b29d1ca8bb46c`).

**`php-cs-fixer.dist.php`** — копия из `slogger.laravel`, finder меняется на:

```
__DIR__ . '/src'
__DIR__ . '/tests'
__DIR__ . '/workbench'
__DIR__ . '/demo/app'
__DIR__ . '/demo/config'
__DIR__ . '/demo/database'
__DIR__ . '/demo/routes'
```

Имя файла — `php-cs-fixer.dist.php` (как в `slogger.laravel`), не `cs-fixer.dist.php`
(как в `sconcur-php`): у нас Laravel-пакет, ориентир — `slogger.laravel`.

Файл содержит русские комментарии («вместо ...», «часть замены для ...»). По правилу
про язык они должны быть английскими — переводим при копировании.

## 1.3 PHPStan

**`phpstan.neon`**:

```neon
parameters:
    paths:
        - ./src
        - ./tests
        - ./workbench
        - ./demo/app
    level: 8

    ignoreErrors:
        # Функции расширения существуют только когда sconcur.so загружен.
        - '#unction SConcur\\Extension\\.* not found#'
        - '#generic class Fiber .* does not specify its types#'
```

Точный список подавлений уточняется по первому прогону: у `sconcur-php` подавления
нужны потому, что анализ идёт без загруженного расширения. У нас расширение вшито в
образ и включено глобально, поэтому часть подавлений может оказаться лишней — лишние
не добавляем.

Отдельно проверить: `src/Foundation/AsyncApplication` наследует
`Illuminate\Foundation\Application`, а `AsyncConfig`/`AsyncRouter`/`AsyncViewFactory`
наследуют классы фреймворка — на level 8 это обычно даёт ошибки по ковариантности
сигнатур. Если объём правок выходит за пределы шага — фиксируем level 6 и заводим план
`.ai/plans/phpstan-level-8.md`.

## 1.4 composer.json

Дополняем существующий файл:

```jsonc
"require-dev": {
    "orchestra/testbench": "^9.0 | ^10.0",
    "phpunit/phpunit": "^11.0",
    "phpstan/phpstan": "^2.1",
    "friendsofphp/php-cs-fixer": "^3.94",
    "guzzlehttp/guzzle": "^7.0"
},
"autoload-dev": {
    "psr-4": {
        "SConcur\\Laravel\\Tests\\": "tests/",
        "Workbench\\App\\": "workbench/app/",
        "Demo\\App\\": "demo/app/"
    }
},
"config": {
    "audit": { "block-insecure": false }
}
```

- `orchestra/testbench` ^9/^10 — соответствует Laravel 11/12 (см. открытый вопрос про
  матрицу в [00-overview.md](00-overview.md)).
- Секция `extra.laravel.providers` уже есть, менять нечего.
- `"version": "dev-master"` в текущем файле надо заменить на реальную семантическую
  версию (`0.1.0`), иначе релизный workflow из [06](06-ci-docs.md) не сможет вывести
  тег. **Требует подтверждения стартовой версии.**

**`composer.lock`** — генерируется первым `make composer c=install` и **коммитится**:
из него Dockerfile берёт версию `sconcur/sconcur` для скачивания `.so`.

## 1.5 .gitignore

Копия из `slogger.laravel` плюс `.mcp.json` (локальный адрес MCP PhpStorm — не то, что
уезжает в публичный пакет).

```
.idea
.mcp.json

.env

/vendor
.phpunit.result.cache
.php-cs-fixer.cache
```

Каталоги демо, которые должны существовать пустыми (`demo/storage/*`,
`demo/bootstrap/cache`), закрываются собственным `.gitignore` в каждом — так же, как в
скелете Laravel. Дублировать их здесь не нужно: правило из более глубокого файла и так
выигрывает, а два места вместо одного расходятся при первой же правке.

## Проверка шага

```bash
make composer c=install
make cs-fixer-check
make stan
```

(`make` появляется на шаге 3; до него — те же команды руками внутри контейнера.)
