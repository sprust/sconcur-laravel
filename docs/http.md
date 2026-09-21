English | [Русский](http.ru.md)

# HTTP responses

How a worker of the `http` group turns what Laravel answers with into what the extension's
HTTP server sends. Every request runs in a coroutine of its own; `LaravelHttpHandler`
(`src/Http/LaravelHttpHandler.php`) hands it to the Laravel kernel and gives the server a
PSR-7 response back. What the server does with that response is in the library's
`vendor/sconcur/sconcur/docs/http-server.md`.

## Table of contents

- [Ordinary responses](#ordinary-responses)
- [Streamed responses](#streamed-responses)
  - [What is streamed](#what-is-streamed)
  - [How the chunks are read](#how-the-chunks-are-read)
  - [Termination](#termination)
  - [Errors](#errors)
  - [A callback that prints](#a-callback-that-prints)
- [Output buffers](#output-buffers)

## Ordinary responses

A response is converted whole by Symfony's `PsrHttpFactory`, and the kernel is terminated
after that (`Kernel::terminate()`). Its size is known, so the server sends it in one write
with a `Content-Length`. A `BinaryFileResponse` goes this way too.

## Streamed responses

A response built from chunks — a generator, `yield` — is streamed: the status, the headers
and the cookies go out first, without `Content-Length`, and the server sends the body
chunked while the generator is still running. The body is not kept anywhere: a chunk leaves
memory once the server has sent it, and the server takes the next one only after the client
has taken the previous one.

### What is streamed

Any of these (`src/Http/StreamedChunks.php`):

- Symfony's `new StreamedResponse($iterable)`, a subclass included — the iterable may be a
  generator, an array or any `Traversable`;
- Laravel's `response()->stream(function () { yield …; })`;
- a `StreamedResponse` whose callback is itself a generator function
  (`->setCallback(fn () => yield …)`).

```php
return new StreamedResponse((static function () use ($cursor): Generator {
    yield '{"items":[';

    foreach ($cursor as $index => $item) {
        yield ($index > 0 ? ',' : '') . json_encode($item);
    }

    yield ']}';
})(), 200, ['Content-Type' => 'application/json']);
```

The first two shapes run outside this server unchanged: under PHP-FPM, Symfony and Laravel
print every chunk and flush it. The third is Octane's: under PHP-FPM the callback's generator
is never iterated and the body is empty.

Recognising the first two shapes means reading the variables of the closure Symfony
and Laravel wrap the chunks in. A shape that is not recognised is not an error: the response
is sent whole, like a callback that prints.

### How the chunks are read

Every chunk yielded is one chunk on the wire, whether or not anything was waited on in
between, so a generator that only computes streams too
(`src/Http/IterableResponseBody.php`).

A generator runs on the stack of whoever advances it — here the request coroutine. So it
works like any code of the handler:

- `request()`, `auth()`, the session and every other per-coroutine service are the ones the
  controller saw;
- what it waits on (a cursor batch, a query, `Sleeper`, a `WaitGroup`) suspends the request
  coroutine the ordinary way and lets the other requests of the worker run;
- preemption and `handlerTimeoutMs` reach it as they reach any handler.

A chunk is a string; an integer, a float, `null` or a `Stringable` is turned into one the way
`echo` would. Anything else ends the body with an `UnexpectedValueException`.

### Termination

`Kernel::terminate()` — terminable middleware and the `terminating` callbacks — runs after
the last chunk, not before the first. When the body is not read to the end (the client went
away, the handler ran past `handlerTimeoutMs`), the kernel is terminated when the server
drops the response. A worker that is being shut down does not terminate it.

A generator is released with the response, so the `finally` blocks of one that was not read
to the end may run after the termination.

### Errors

The status and the headers go out before the first chunk is taken, so the status cannot
change. An exception from the generator ends the body: it is reported to Laravel's exception
handler (`report()`), the stream is closed cleanly, and the kernel is terminated all the
same. The client sees a body cut short, not an error page. A stop — `handlerTimeoutMs`, a
shutdown — is not reported.

`handlerTimeoutMs` covers the whole request, the streaming included.

### A callback that prints

A `StreamedResponse` whose callback prints with `echo` is not streamed: it is sent whole, as
an ordinary response. `PsrHttpFactory` runs the callback to its end into `php://temp` (the
first 2 MB in memory, the rest on disk), and the client gets the first byte when the callback
is done. This covers `StreamedJsonResponse`, `response()->streamJson()`,
`response()->streamDownload()`, `response()->eventStream()` and every callback with `echo`.

To stream such a response, write it with `yield`:

```php
// sent whole
return new StreamedResponse(static function () use ($rows): void {
    foreach ($rows as $row) {
        echo implode(',', $row) . "\n";
    }
});

// streamed
return new StreamedResponse((static function () use ($rows): Generator {
    foreach ($rows as $row) {
        yield implode(',', $row) . "\n";
    }
})());
```

A printing callback runs under an output buffer, so everything in [Output
buffers](#output-buffers) applies to it.

## Output buffers

PHP's output buffers (`ob_start()`, `ob_get_clean()`) belong to the process, not to a
coroutine. A coroutine that is suspended with a buffer open leaves that buffer on the stack
for the others: a neighbour opens its own above it, and every `ob_get_clean()` after that
takes the buffer on top — not necessarily its own. The output of two requests then mixes.

A coroutine is suspended while a buffer is open in two cases:

- it waits on a feature inside the buffer — a query while a view renders (a relation
  loaded lazily), a Redis command, `Sleeper`;
- preemption parks it: the HTTP server interrupts a handler every `preemptionQuantumMs` of
  CPU (5 ms by default), so a render longer than that is enough.

This is how Blade and every PHP template engine render: `ob_start()`, the template,
`ob_get_clean()`. It is also how a printing `StreamedResponse` is run. Views that load no
data and render within the quantum are not affected; views that wait inside or render longer
are, as soon as two such requests run in one worker.

What keeps a response out of it:

- load the data before rendering, so that nothing waits inside a view;
- stream with `yield` rather than with `echo` — a generator uses no buffer at all;
- `preemptionQuantumMs = 0` in the `server` block of the `http` group turns preemption off.
  That removes the second case but not the first, and lets a CPU-bound handler hold the
  other requests of its worker.
