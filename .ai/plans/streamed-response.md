# Потоковая отдача StreamedResponse

Статус: реализован путь кусков (`src/Http/StreamedChunks.php`,
`src/Http/IterableResponseBody.php`, «Шаг 2» ниже). Путь `echo` (шаги до него) был сделан и
удалён решением мейнтейнера (см. «Шаг 3»): описание ниже оставлено как история решения.
Тесты: `tests/Feature/Http/StreamedResponseTest.php`. Документация: `docs/http.md`.

## Проблема

`src/Http/LaravelHttpHandler.php` отдаёт любой ответ через
`PsrHttpFactory::createResponse()`. Для `StreamedResponse` (и `StreamedJsonResponse`)
фабрика выполняет колбэк синхронно под `ob_start()` в `php://temp` и отдаёт серверу тело
известного размера. Клиент не получает ни байта, пока колбэк не закончит; тело целиком
лежит во временном файле (после 2 МБ — на диске).

Сервер sconcur умеет стримить сам: PSR-ответ с телом `getSize() === null` он читает
`read(65536)` в корутине запроса и шлёт chunked-ом с backpressure
(`HttpServer::stream()`).

## Что выяснено до плана

1. **`Fiber::suspend()` внутри обработчика `ob_start` недопустим.** Пока обработчик
   выполняется, PHP держит глобальный флаг «идёт вывод через обработчик»
   (`OG(running)`). Если приостановить Fiber изнутри обработчика, флаг остаётся
   поднятым, и первый же `ob_start()` в любой другой корутине (Blade, PsrHttpFactory
   соседнего запроса) — fatal error «Cannot use output buffering in output buffering
   display handlers». Проверено на PHP 8.4 в `scl-php`. Кроме того, буферы вывода
   глобальны, а не пофайберные: пока наш уровень на стеке, вывод соседей попадает в него.
   Значит, вариант из задачи «обработчик ob делает `Fiber::suspend($buffer)`» не годится.

2. **Вложенный Fiber без регистрации ломает всё остальное.** Контекст корутины и флоу
   ключуются по `spl_object_id(Fiber::getCurrent())`: во вложенном Fiber
   `Context::current()` не видит request, а фичи (MySQL, Redis, Sleeper) уходят в
   синхронный путь и блокируют весь воркер.

3. **Рабочая схема — «батут» (проверена прототипом).** Колбэк исполняется во вложенном
   Fiber, который зарегистрирован в `State` на флоу корутины запроса
   (`State::registerFiberFlow`) и с родительским контекстом корутины запроса
   (`State::registerCoroutineContext`). Когда колбэк ждёт фичу, он приостанавливает
   вложенный Fiber с `PendingPushDto`; `read()` (в корутине запроса) снимает наш уровень
   буфера, отдаёт накопленный вывод серверу как кусок, а при следующем `read()`
   пробрасывает отложенный `PendingPushDto` планировщику своим `Fiber::suspend()` и
   возвращает результат во вложенный Fiber. Владелец задачи для планировщика — корутина
   запроса, так что маршрутизация результатов не меняется. Прототип: колбэк печатает 5
   кусков с `Sleeper::usleep(200 мс)` — куски приходят каждые ~200 мс, контекст виден,
   соседняя корутина не блокируется, общее время ~1 с.

Следствие: **граница куска — это ожидание колбэка** (запрос в БД, Sleeper, HTTP-клиент,
любая фича) **или его конец.** `echo` + `flush()` без ожидания между ними не дают
границы: такой колбэк и так держит весь воркер, а его вывод копится в памяти до
ближайшего ожидания. Для сценария из задачи (чтение из базы по мере вывода) граница есть
на каждом запросе к базе.

## Решение

### `src/Http/StreamedResponseBody` (новый, `implements StreamInterface`)

- Конструктор: `StreamedResponse $response`, `Closure $onFinished` (вызов `terminate()`).
- `getSize()` → `null`, `isSeekable()`/`isWritable()` → `false`, `isReadable()` → `true`,
  `__toString()`/`getContents()` дочитывают до конца.
- Вложенный Fiber создаётся лениво при первом `read()` (то есть уже в корутине запроса,
  внутри `HttpServer::stream()`), регистрируется в `State` (флоу — только если корутина
  запроса асинхронная; вне корутины фичи идут синхронно, как сейчас) и снимается с учёта
  (`State::unRegisterFiber`) сразу после завершения — id переиспользуются.
- Буфер вывода: `ob_start(обработчик)` открывается перед каждым входом во вложенный Fiber и
  снимается `ob_end_flush()` при каждом выходе из него; обработчик дописывает в строку и
  возвращает `''` (так `ob_flush()` внутри колбэка, как в `response()->eventStream()`,
  тоже попадает в поток, а не в stdout воркера). Пока вложенный Fiber приостановлен, нашего
  уровня на стеке нет.
- Если колбэк сам открыл уровни буфера и приостановился внутри них (Blade-шаблон с
  запросом в БД), наш уровень не на вершине — тогда кусок не отдаётся, приостановка
  пробрасывается как есть (ровно как сейчас с любым открытым буфером в корутине).
- `read($length)` отдаёт не больше `$length` из накопленного, остаток держит.
- Исключение из колбэка: уровень буфера снимается, `onFinished` вызывается, исключение
  пробрасывается из `read()` — `HttpServer::stream()` отдаст его в `onError` и закроет
  поток чисто (статус уже ушёл).
- Исключение, брошенное планировщиком в корутину запроса (дедлайн `handlerTimeoutMs`,
  остановка): пробрасывается во вложенный Fiber (`Fiber::throw`), чтобы отработали его
  `finally`, затем наружу.
- `__destruct()` / `close()`: если поток не дочитан (клиент ушёл, таймаут посреди
  стрима) — вложенный Fiber закрывается, `onFinished` вызывается, если корутина ещё может
  ждать (`Scheduler::canAwait()`); при остановке процесса — нет.

### `src/Http/LaravelHttpHandler::__invoke`

- `StreamedResponse` (включая `StreamedJsonResponse`): заголовки и статус строятся тем же
  `PsrHttpFactory` на пустом `Response` с копией заголовков (так cookie и регистр
  заголовков совпадают с обычным путём), затем `withoutHeader('Content-Length')` и
  `withBody(new StreamedResponseBody(...))`. `terminate()` — в `onFinished`, после того как
  тело дочитано.
- Остальные ответы — без изменений, `terminate()` там же, где сейчас.

### `src/Support/Coroutine::isActive()`

Во вложенном Fiber `Scheduler::canAwait()` отвечает «нет» (планировщик его не знает), и
кооперативные замены (`CooperativeSleep`, Files, `Lock::block`) ушли бы в нативные
блокирующие вызовы. `StreamedResponseBody` запоминает ответ `canAwait()` корутины
запроса перед каждым входом во вложенный Fiber, а `isActive()` для такого Fiber берёт его.

### Ограничения (в документацию)

- Граница куска — ожидание колбэка или его конец (см. выше).
- `WaitGroup::waitAll()`/`iterate()` внутри колбэка не поддерживается: планировщик
  будит ждущего по id корутины, а вложенный Fiber корутиной не является. Ожидание бросает
  `FiberStateException` поверх `LogicException`, а не зависает: такое ожидание приостанавливает Fiber без
  `PendingPushDto`/`PendingNextDto`, и это распознаётся.
- `handlerTimeoutMs` не трогаем: он покрывает и стриминг.

## Как это выглядит в коде

### Приложение: не меняется ничего

Ответ пишется так же, как под php-fpm, — `StreamedResponse` с `echo` в колбэке. Пример в
форме `TraceTreeResponse` (данные из курсора sconcur Mongodb):

```php
class TraceTreeResponse extends StreamedResponse
{
    public function __construct(TraceTreeResultObject $resource)
    {
        parent::__construct(
            static function () use ($resource): void {
                echo '{"items":[';

                $encoded = [];
                $written = false;

                // Итерация курсора: на каждой следующей пачке курсор ждёт фичу Mongodb,
                // колбэк приостанавливается, и всё, что напечатано с прошлой пачки,
                // уходит клиенту одним chunk-ом.
                foreach ($resource->items as $item) {
                    $encoded[] = json_encode(self::node($item));

                    if (count($encoded) < self::NODES_PER_WRITE) {
                        continue;
                    }

                    echo ($written ? ',' : '') . implode(',', $encoded);

                    $written = true;
                    $encoded = [];
                }

                if ($encoded !== []) {
                    echo ($written ? ',' : '') . implode(',', $encoded);
                }

                echo ']}';
            },
            200,
            ['Content-Type' => 'application/json'],
        );
    }
}
```

Что меняется в поведении, без правок кода:

| | Сейчас | После |
|---|---|---|
| Первый байт у клиента | после конца колбэка | после первой пачки курсора |
| Где лежит тело | целиком в `php://temp` (диск после 2 МБ) | нигде: кусок ушёл — буфер пуст |
| Память | растёт вместе с `php://temp` | пачка курсора + то, что напечатано с прошлой пачки |
| Заголовки | `Content-Length` | `Transfer-Encoding: chunked`, без `Content-Length` |
| `terminate()` | до сериализации тела | после последнего куска |
| Ошибка в колбэке | 500 | статус уже ушёл: `onError`, поток закрыт чисто |

Размер куска задаёт размер пачки курсора (`batchSize` запроса к Mongodb), а не
`NODES_PER_WRITE`: `echo` между двумя ожиданиями копится в буфере моста и уходит одним
куском. `NODES_PER_WRITE` остаётся полезным как экономия на числе `echo`, но на границы
не влияет. Строки в JSON — сколько угодно мелких `echo`, это не дороже.

### Мост: `LaravelHttpHandler::__invoke`

```php
$response = $kernel->handle($laravelRequest);

if ($response instanceof StreamedResponse) {
    return $this->streamedResponse(
        kernel: $kernel,
        laravelRequest: $laravelRequest,
        response: $response,
    );
}

$kernel->terminate($laravelRequest, $response);

return $this->psrHttpFactory->createResponse($response);
```

```php
private function streamedResponse(Kernel $kernel, Request $laravelRequest, StreamedResponse $response): ResponseInterface
{
    // Статус, заголовки и cookie — тем же путём, что у обычного ответа, но без тела.
    $head = new Response(status: $response->getStatusCode());

    $head->headers = clone $response->headers;
    $head->setProtocolVersion($response->getProtocolVersion());

    return $this->psrHttpFactory->createResponse($head)
        ->withoutHeader('Content-Length')
        ->withBody(
            new StreamedResponseBody(
                response: $response,
                onFinished: static fn() => $kernel->terminate($laravelRequest, $response),
            ),
        );
}
```

### Мост: `StreamedResponseBody::read()` (суть)

```php
public function read(int $length): string
{
    while ($this->buffer === '' && !$this->finished) {
        $this->step(); // один проход колбэка до следующего ожидания или до конца
    }

    $chunk        = substr($this->buffer, 0, $length);
    $this->buffer = substr($this->buffer, strlen($chunk));

    return $chunk;
}

private function step(): void
{
    // Ожидание колбэка, отложенное прошлым read(): отдаём планировщику от имени
    // корутины запроса, результат возвращаем колбэку.
    $resumeValue = $this->hasPendingSuspend ? Fiber::suspend($this->pendingSuspend) : null;

    $this->openBuffer();   // ob_start с обработчиком, дописывающим в $this->buffer

    try {
        $suspendValue = $this->fiber->isStarted()
            ? $this->fiber->resume($resumeValue)
            : $this->fiber->start();
    } finally {
        $this->closeBuffer(); // ob_end_flush: пока колбэк стоит, нашего уровня на стеке нет
    }

    // ...колбэк закончил → finished + onFinished; иначе запомнить $suspendValue
}
```

## Тесты

`tests/Feature/Http/StreamedResponseTest.php`, маршруты в `workbench`, корутины через
`WaitGroup` с настоящим `Sleeper`:

- колбэк печатает 1000 кусков с паузой: первый кусок прочитан до конца колбэка; у ответа
  нет `Content-Length`, `getSize()` — `null`;
- память: 1000 кусков по 64 КБ — прирост пиковой памяти порядка одного куска, а не 64 МБ;
  `php://temp` не используется;
- колбэк видит тот же request и тот же экземпляр скоупного сервиса, что и контроллер;
- `terminate()` (terminating-колбэк приложения) срабатывает после последнего куска, а не
  до первого;
- соседняя корутина не блокируется, пока колбэк ждёт;
- `StreamedJsonResponse` отдаётся потоком;
- исключение из колбэка: `read()` бросает его, `terminate()` вызван, в `State` не осталось
  регистрации вложенного Fiber;
- поток брошен недочитанным: `terminate()` вызван, регистрация снята;
- вне корутины (синхронный путь) ответ дочитывается целиком и совпадает с прежним;
- обычный JSON-ответ — по-прежнему известного размера, с прежними заголовками.

Ручная проверка на живом сервере: маршрут `/stream` в demo, `curl -N` через nginx —
куски приходят по мере вывода, `Transfer-Encoding: chunked`.

## Документация

Новая пара `docs/http.md` / `docs/http.ru.md`: как мост отдаёт ответы, потоковые ответы,
где граница куска, порядок `terminate()`, ограничения. Строка в таблице `## Documentation`
обоих README, ссылка из строки HTTP-сервера в `## The runtimes`, `.ai/README.md` —
новый класс в разделе Architecture.

## Шаг 2: путь кусков (без `echo`)

Решение мейнтейнера: основной путь — генератор. Генератор выполняется на стеке того, кто его
продвигает, поэтому читается прямо в корутине запроса: без отдельного Fiber, без буфера
вывода, кусок на каждый `yield` — даже без ожиданий между ними (проверено на живом пуле:
генератор, который только считает, отдаёт первый кусок сразу). `WaitGroup`, вытеснение и
дедлайн работают как в обычном обработчике.

Распознаются (`StreamedChunks::of`):

- колбэк — функция-генератор (форма Octane, `setCallback(fn () => yield …)`);
- `new StreamedResponse($iterable)` из Symfony 7.3+ — замыкание `setChunks()` держит
  iterable в `$chunks`, scope — `StreamedResponse`;
- `response()->stream(fn () => yield …)` из Laravel — замыкание держит функцию-генератор в
  `$callback`, scope — `ResponseFactory`.

Переменные замыканий — внутренности Symfony и Laravel: если они поменяются, форма просто
перестанет распознаваться и ответ пойдёт путём `echo`, а не сломается.

Всё остальное (`StreamedJsonResponse`, `eventStream()`, `streamDownload()`, колбэк с `echo`)
в этом шаге шло путём `echo`; см. «Шаг 3».

Порядок завершения: `terminate()` после последнего куска. Недочитанный генератор держит и
сам `StreamedResponse`, поэтому его `finally` срабатывает при освобождении ответа — может
быть после `terminate()`.

Смешивание буферов вывода в корутинах (Blade с ожиданием внутри или дольше кванта
вытеснения) описано в `docs/http.md`, раздел «Output buffers»; исправление — задача
для sconcur (сохранять и восстанавливать буферы корутины на каждой приостановке), поддержка
Blade — потом.

## Шаг 3: путь `echo` удалён

Решение мейнтейнера: стримится только генератор. Путь `echo` (`StreamedResponseBody`,
`Coroutine::lend()`) удалён: он опирался на внутренности sconcur (`State`,
`PendingPushDto`/`PendingNextDto`), не умел `WaitGroup` в колбэке, а вытеснение его не
прерывало. `StreamedResponse` с `echo` снова отправляется целиком через `PsrHttpFactory`, но
`terminate()` теперь вызывается после преобразования ответа (раньше — до, и колбэк
выполнялся уже после `terminate()`). В `docs/http.md` сказано, что такой ответ не стримится
и как переписать его на `yield`.

Если понадобится стримить ответы, которые пишутся через `echo` и не переписываются
(`streamDownload()` в сторонних пакетах), правильный путь — поддержка в sconcur: ответ,
тело которого — вывод самой корутины, и отправка накопленного вывода на каждой
приостановке корутины. Тогда мост обойдётся без отдельного Fiber.
