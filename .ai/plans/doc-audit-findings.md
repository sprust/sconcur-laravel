# Находки сверки документации с кодом (sconcur 0.18.0)

При сверке всей документации с кодом нашлись места, где неверен был не текст, а код. Все
пункты ниже исправлены; доки приведены к новому поведению. Открытым остаётся только issue в
библиотеку (п. 1) и непроверенное из п. 7.

## 1. Отсутствующая очередь роняла весь мастер — исправлено

Было: консьюмер на неопубликованной очереди получал 404 раньше, чем сообщал мастеру, что
поднялся; когда так падали все слоты группы, `WorkerGroup::neverCameUp()` останавливал
мастер с кодом 70, HTTP вместе с ним, а supervisor запускал его в ту же ошибку.

Сделано: роль `rabbitmq` (`src/Servers/WorkerRoles.php`) вызывает
`Scheduler::get()->markReady()` до `consume()`. Падение теперь обычный перезапуск с
backoff, мастер и HTTP живут. Цена: группа, которая не поднимется никогда, не остановит
мастер кодом 70, ошибка брокера видна только в журнале воркера. Слушатели `WorkerForked`
по-прежнему выполняются до `markReady()`, так что их исключение — всё ещё неудачный старт.
Тест: `MasterRunTest::aQueueThatIsNotDeclaredDoesNotStopTheMaster` (без правки падает с
`stopping with code 70`). Доки: `docs/queue*.md`.

Issue в библиотеку (не заведён):

> `QueueConsumer::consume()` does not retry the first open. A consumer on a queue that
> does not exist gets `404 NOT_FOUND` about a millisecond after start, before
> `Scheduler::markReady()`, so under `WorkerMaster` every slot counts as a failed start and
> `neverCameUp()` stops the whole master — every other group included — with code 70.
> Suggestions: retry the first open with a pause, or a group policy "do not stop the master if this group never came up".
> `docs/amqp.md` ("A supervised consumer") still says the consumer is "restarted over and
> over", which is no longer what happens.

## 2. `SCONCUR_RABBITMQ_MEMORY_MB` ничего не делал — удалён

Ключ `sconcur.queue.rabbitmq.memory_mb` и переменная убраны из скелета, demo, workbench,
`ConsumerRunner` и `docs/configuration*.md`. Рабочий лимит —
`SCONCUR_RABBITMQ_MAX_MEMORY_BYTES`. Опубликованный ранее конфиг с ключом не ломается: его
просто никто не читает.

## 3. `response()->eventStream()` стримился без разметки — исправлено

`src/Http/StreamedChunks.php` узнаёт замыкание `eventStream()` по `$endStreamWith` рядом с
`$callback` и отдаёт события по одному на кусок, размеченные так же, как их печатает
`ResponseFactory::eventStream()` (`StreamedEvent`, `Js::encode`, закрывающее событие).
`connection_aborted()` не повторяется: ушедший клиент обрывает чтение генератора сам.
Тест: `StreamedResponseTest::laravelsEventStreamIsReadAnEventPerChunk` сравнивает байты с
нативным выводом замыкания. Доки: `docs/http*.md`, `.ai/README.md`.

## 4. `Redis::disconnect()` уходил на сервер командой — исправлено

`SConcur\Laravel\Redis\Connection::disconnect()` ничего не делает: сокета у объекта нет,
пул dsn общий на процесс, и закрывать его библиотека не даёт (закрывает сама по простою).
Тест: `FacadeTest::disconnectSendsNothingAndLeavesTheConnectionWorking` (без правки —
`ERR unknown command 'DISCONNECT'`). Доки: таблица отличий в `docs/redis*.md`.
`RedisManager::purge()` в `MasterStartCommand` и раньше `disconnect()` не звал.

## 5. Пустой `disconnect()` у `sconcur_mysql` — оставлен, объяснён в докблоке

## 6. `accessLog` вне опубликованного конфига — теперь `file`

`MasterFactory` берёт `AccessLogTarget::File`, как скелет. Тест:
`MasterConfigTest::withoutAnAccessLogKeyTheAccessLogGoesToAFile`.

## 7. Мелочи

- `sconcur:extension:load` без аргумента создаёт `servers/sconcur` — сделано.
- `TaskPoolController::finished()`: недостижимая ветка удалена — дедлайн ставят только
  `requestStop()` и повторный сигнал, оба при `stopRequested`, который не сбрасывается.
- Раздел про confirms перенесён из `docs/database*.md` в «The connection» `docs/queue*.md`.
- `SCONCUR_PANEL_HOST` убран из минимального `.env` в `docs/installation*.md`.
- Не проверено: версии `laravel-echo` 2.4.0 / `pusher-js` 8.6.0 в `docs/websocket*.md`
  (в репозитории нет `package.json`), segfault при preemption внутри `ob_start`, замер
  «39 из 40», внутренности sqlx/redis-rs, происхождение таблицы префиксов из `MONITOR`.
