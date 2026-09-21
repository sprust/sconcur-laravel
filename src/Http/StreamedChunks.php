<?php

declare(strict_types=1);

namespace SConcur\Laravel\Http;

use Closure;
use Illuminate\Routing\ResponseFactory;
use ReflectionFunction;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The chunks a StreamedResponse was built from, when it was built from chunks rather than
 * from a callback that prints.
 *
 * Three shapes are recognised, all of which come down to a StreamedResponse whose callback
 * is a closure:
 *
 * - the callback is itself a generator function — the form Laravel's
 *   `response()->stream()` returns under Octane, and `setCallback(fn () => yield …)`;
 * - Symfony's `new StreamedResponse($iterable)` / `setChunks()`: the callback echoes the
 *   iterable it keeps as `$chunks`;
 * - Laravel's `response()->stream(fn () => yield …)`: the callback echoes the generator
 *   function it keeps as `$callback`.
 *
 * The last two are read out of the closure's variables, which ties them to how Symfony and
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
            return static fn(): iterable => $generatorFunction();
        }

        return null;
    }
}
