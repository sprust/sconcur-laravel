# Переход на sconcur 0.17.0: мастер в процессе приложения

## Что изменилось в библиотеке (0.15.0 → 0.17.0)

- `MasterCli`, `MasterConfig`, `MasterReloadFile`, `Heartbeat` удалены. Мастер — это
  `new WorkerMaster(runtimeDir, groups: [new WorkerGroupConfig(name, role: Closure, ...)])`
  и `->run()`; воркер — `fork` процесса мастера, который выполняет `role(WorkerContext)`.
- Reload нет: новый код — новый мастер. Stop/status — через `MasterControl`.
- `restartPolicy`, `shutdownTimeoutMs`, backoff, `watchdogTimeoutMs` — настройки группы;
  новые `readyTimeoutMs`, `accessLog`, `env`.
- До `run()` нельзя асинхронных вызовов (`ForkRefusedException`) и открытых соединений.
- `reusePort` удалён: `SO_REUSEPORT` всегда. `--masterPid` больше не нужен —
  `WorkerContext::current()`. Сигналы — через расширение, без `pcntl`.
- `ext-msgpack` больше не требуется; строки SQL приходят map'ом в порядке колонок.

## Решения (согласованы с мейнтейнером)

- Роль группы вызывает runner напрямую (`WorkerRoles`), без artisan-команды внутри fork.
  Группа называет роль ключом `role`: `http` | `rabbitmq` | `ws` | `tasks`.
- `sconcur:servers:master:reload` и make-цели `*-reload` удалены. Демо-страница размеров
  пулов перезапускает мастер: `ScalingTask` зовёт `MasterControl::stop(timeoutMs: 0)` и не
  ждёт, supervisor поднимает новый.
- Колбэк после fork — событие `WorkerForked(WorkerContext)`, поднимается ролью до runner'а;
  слушатели через `config('sconcur.listeners')` или `Event::listen`.

## Что сделано

- `MasterFactory` строит `WorkerMaster` из `config('sconcur.master')`: верхний уровень —
  мастер и значения по умолчанию для групп, группа может переопределить.
- `MasterStartCommand` закрывает соединения DB/Redis перед `run()`; `stop`/`status` — через
  `MasterControl` (статус «stopped» — код 3, как раньше).
- `MasterGroups` ищет группу по роли (заменил `WsGroupConfig`), `ServerFlags` — блок
  `server` во флаги. Предупреждение о presence store переехало в `WsServerRunner`.
- `TaskPoolController` зовёт `Scheduler::markReady()`; ненулевой код пула роль отдаёт через
  `exit()`.
- Тесты: `MasterRunTest` (живой мастер через testbench: fork, `WorkerForked`, status, stop),
  `ServerFlagsTest` (библиотека принимает ключи скелета конфига), `ColumnOrderTest`.

## Следующий шаг (сделан)

- `TaskPool` слушает `SIGTERM`/`SIGINT` через `ProcessControl::watchSignals()`, контролёр
  забирает их `takeSignals()` на каждом тике и сверяет мастера по `parentPid()`. `ext-pcntl`
  убран из образа. `ProcessControl` в библиотеке помечен `@internal`; публичный
  `StopRequest` не подходит — он «липкий» и не различает повторный сигнал и уход мастера.
  `SIGQUIT` больше не слушается: в `ProcessSignalEnum` его нет.
- `accessLog` по умолчанию `file` (пакет и демо; workbench оставлен на `stdout`).
- `env` в конфиге: на верхнем уровне — для всех групп, у группы — поверх, её значение
  побеждает.
