<?php

declare(strict_types=1);

namespace SConcur\Laravel\Http;

use Closure;
use Generator;
use Illuminate\Http\StreamedEvent;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Js;
use ReflectionFunction;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The chunks a StreamedResponse was built from, when it was built from chunks rather than
 * from a callback that prints.
 *
 * Four shapes are recognised, all of which come down to a StreamedResponse whose callback
 * is a closure:
 *
 * - the callback is itself a generator function — the form Laravel's
 *   `response()->stream()` returns under Octane, and `setCallback(fn () => yield …)`;
 * - Symfony's `new StreamedResponse($iterable)` / `setChunks()`: the callback echoes the
 *   iterable it keeps as `$chunks`;
 * - Laravel's `response()->stream(fn () => yield …)`: the callback echoes the generator
 *   function it keeps as `$callback`;
 * - Laravel's `response()->eventStream(fn () => yield …)`: the same, with `$endStreamWith`
 *   beside it, and every message echoed as a server-sent event. The chunks are the events,
 *   framed the way that callback frames them.
 *
 * The last three are read out of the closure's variables, which ties them to how Symfony and
 * Laravel write those closures. A shape that is not recognised is not an error: the
 * response goes the way a printing callback goes.
 */
class StreamedChunks
{
    /**
     * @return null|Closure(): iterable<mixed> called when the body is first read
     */
    public static function of(StreamedResponse $response): ?Closure
    {
        $callback = $response->getCallback();

        if ($callback === null) {
            return null;
        }

        $reflection = new ReflectionFunction($callback);

        if ($reflection->isGenerator()) {
            return static fn(): iterable => $callback();
        }

        $scope     = $reflection->getClosureScopeClass()?->getName();
        $variables = $reflection->getClosureUsedVariables();

        if ($scope === null) {
            return null;
        }

        $chunks = $variables['chunks'] ?? null;

        if (is_a($scope, StreamedResponse::class, true) && is_iterable($chunks)) {
            return static fn(): iterable => $chunks;
        }

        $generatorFunction = $variables['callback'] ?? null;

        if (is_a($scope, ResponseFactory::class, true)
            && $generatorFunction instanceof Closure
            && new ReflectionFunction($generatorFunction)->isGenerator()
        ) {
            if (array_key_exists('endStreamWith', $variables)) {
                $endStreamWith = $variables['endStreamWith'];

                return static fn(): iterable => static::events(
                    generatorFunction: $generatorFunction,
                    endStreamWith: $endStreamWith,
                );
            }

            return static fn(): iterable => $generatorFunction();
        }

        return null;
    }

    /**
     * What ResponseFactory::eventStream() echoes, an event per chunk: every message, then
     * the closing one unless it is blank.
     *
     * @return Generator<int, string>
     */
    protected static function events(Closure $generatorFunction, mixed $endStreamWith): Generator
    {
        foreach ($generatorFunction() as $message) {
            yield static::event($message);
        }

        if (filled($endStreamWith)) {
            yield static::event($endStreamWith);
        }
    }

    protected static function event(mixed $message): string
    {
        $event = 'update';

        if ($message instanceof StreamedEvent) {
            $event   = $message->event;
            $message = $message->data;
        }

        if (!is_string($message) && !is_numeric($message)) {
            $message = Js::encode($message);
        }

        return "event: {$event}\ndata: {$message}\n\n";
    }
}
