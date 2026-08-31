<?php

declare(strict_types=1);

namespace SConcur\Laravel\Foundation;

use Closure;

/**
 * Proxy returned to Laravel Facades for scoped services.
 *
 * Facades cache the resolved instance in a static array — shared across
 * coroutines. Instead of clearing that cache per request (which races), we cache
 * this proxy once; every facade call goes through __call → resolver →
 * Context::current() → the correct per-coroutine instance.
 *
 * DI by type (make/resolve) bypasses offsetGet and gets the real instance, so
 * type-hints keep working.
 *
 * Ported from yangusik/laravel-spawn (ScopedServiceProxy).
 */
class ScopedServiceProxy
{
    public function __construct(
        private readonly Closure $resolver,
    ) {
    }

    public function __call(string $method, array $args): mixed
    {
        return ($this->resolver)()->$method(...$args);
    }

    public function __get(string $property): mixed
    {
        return ($this->resolver)()->$property;
    }

    public function __set(string $property, mixed $value): void
    {
        ($this->resolver)()->$property = $value;
    }

    public function __isset(string $property): bool
    {
        return isset(($this->resolver)()->$property);
    }
}
