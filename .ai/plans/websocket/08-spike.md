# Этап 0: результаты спайка

Проверено в контейнере `php` (`sconcur.so` 0.12.1, PHP 8.4.15) против живого
RabbitMQ. Скрипты жили в `/tmp` контейнера, в `src/` этап ничего не оставил.

## 1. `Scheduler::spawn` из корутины обработчика

Работает, и порождённая корутина переживает возврат родительской.

```
[+01.030] handler: path='/app/testkey'
[+01.030] child: started
[+01.030] handler: spawn returned normally
[+01.031] handler: read returned null, returning
[+02.031] child: tick 1
...
[+09.041] child: finished
```

Родитель вернулся на 1.031, ребёнок тикал до 9.041 — вложенный запуск легален, и
жизнь ребёнка от родителя не зависит.

## 2. Порождённая корутина держит graceful shutdown

Подтверждено ровно то, чего опасался план. `SIGTERM` пришёл на 2.35, сервер вышел
на 9.041 — то есть дождался последнего тика ребёнка, а диагностика простоя назвала
виновника поимённо:

```
sconcur ws server shutdown: stop accepting (reason=signal), draining 1 in-flight
sconcur ws server shutdown: drain stalled: 1 in-flight, 1 go tasks, 0 dropped,
    tracked=[sp_2=suspended], waiters=[], switched=0, parked on [sp_2:10]
```

Значит, вечная корутина-подписчик недопустима, и привязка её жизни к реестру
соединений — не перестраховка, а обязательное условие.

## 3. Что попадает в `Connection::path`

Путь без query-строки, и сравнение `path` на стороне расширения её тоже не
учитывает. Сервер с `path: '/app/testkey'`, четыре попытки подключения:

| URL | Итог |
|---|---|
| `/app/testkey?protocol=7&client=js&version=8.4.0` | принят, `path='/app/testkey'` |
| `/app/testkey` | принят, `path='/app/testkey'` |
| `/app/wrongkey?protocol=7` | `404` на рукопожатии |
| `/` | `404` на рукопожатии |

Отсюда `path` группы — точная строка `/app/{app_key}`: чужой ключ отсекается до
PHP, соединение под него не поднимается.

## 4. `WsServer::fromArgs`

У конструктора двадцать параметров, семнадцать проходят через argv. Не проходят
`allowedOrigins`, `subprotocols` (массивы) и `onError` (замыкание) — ни один из них
не нужен. Весь предлагаемый набор флагов, включая `--path=` с пустым значением и
`--masterPid=1`, разобран без ошибок. Runner устроен как `HttpServerRunner`.

## 5. Главная проверка: шина внутри ws-сервера

Собран весь путь целиком — ws-сервер, подписчик fanout в корутине, посторонний
процесс-издатель, ws-клиент:

```
[+00.996] handler: sock-0 registered, connections=1
[+00.998] handler: subscriber spawned
[+01.004] bus: queue=amq.gen-vNQpHciWNTl2SMw1poJgaA bound to sconcur.ws.spike
[+03.011] bus: idle wake #1 (Consumer timeout exceed)
[+04.011] bus: delivery event-alpha
[+05.059] bus: delivery event-beta
[+05.059] handler: sock-0 unregistered, connections=0
[+07.065] bus: idle wake #2 (Consumer timeout exceed)
[+07.065] bus: registry empty, subscriber exits after 2 idle wakes
          sconcur ws server shutdown: drained all in-flight
[+07.066] server: serve() returned
```

Клиент получил обе публикации: `push[sock-0]:event-alpha`,
`push[sock-0]:event-beta`. Отключение клиента опустошило реестр, подписчик ушёл на
следующем пробуждении, и `SIGTERM` завершил сервер за миллисекунду.

Архитектура из `03-bus-and-broadcasting.md` работает.

## 6. Найденная ошибка плана: `autoDelete` у очереди воркера

Первый прогон пункта 5 умер на второй секунде:

```
[+02.038] bus: idle wake #1 (Consumer timeout exceed)
[+02.039] bus: idle wake #2 (Server channel error: 404, message: NOT_FOUND -
              no queue 'amq.gen-nVm9wxy9F-xlSRkIwz5hMg' in vhost '/')
[+02.039] bus: FAILED ChannelException: Could not start the consumer. No channel available.
```

Причина: подписчик выходит из генератора `consume()` на каждом простое, чтобы
проверить реестр, а выход отменяет потребителя. С `autoDelete: true` брокер сносит
очередь как оставшуюся без потребителей — и следующий `consume()` получает `404`,
который заодно убивает канал.

Исправлено в `03-bus-and-broadcasting.md`: `exclusive: true, autoDelete: false`.
Оттуда же второе правило — ошибка канала лечится переоткрытием канала, а не только
потребителя.

## Что изменилось в плане

| Файл | Правка |
|---|---|
| `01-protocol.md` | `path` — точная строка `/app/{key}`, а не `''` |
| `02-runtime.md` | вопросы спайка сняты, запасные варианты для подписчика убраны |
| `03-bus-and-broadcasting.md` | `autoDelete: false`, переоткрытие канала |
| `05-config-and-infra.md` | `path` по умолчанию, `read_timeout_seconds` 1.0 → 5.0 |

`read_timeout_seconds` поднят до 5 секунд: простой стоит пары RPC на переоткрытие
потребителя, а платит за него только молчащий воркер — на нагруженном таймаут не
срабатывает вовсе. Пять секунд укладываются в `shutdownTimeoutMs` мастера с запасом.
