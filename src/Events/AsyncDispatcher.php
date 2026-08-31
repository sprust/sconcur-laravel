<?php

declare(strict_types=1);

namespace SConcur\Laravel\Events;

use Illuminate\Events\Dispatcher;
use SConcur\Context\Context;

/**
 * Coroutine-safe event dispatcher.
 *
 * Before bootCompleted(): behaves like the stock Dispatcher.
 * After bootCompleted(): defer() state lives per-coroutine in the context. The
 * listener registry stays shared — listeners are the same for every request.
 *
 * Ported from yangusik/laravel-spawn (AsyncDispatcher).
 */
class AsyncDispatcher extends Dispatcher
{
    private const string CTX_KEY = 'events.defer';

    private bool $async = false;

    public function bootCompleted(): void
    {
        $this->async = true;
    }

    public function defer(callable $callback, ?array $events = null)
    {
        if (!$this->async) {
            return parent::defer($callback, $events);
        }

        $ctx  = Context::current();
        $prev = $ctx->find(self::CTX_KEY);

        $ctx->set(self::CTX_KEY, [
            'deferring' => true,
            'deferred'  => [],
            'events'    => $events,
        ], replace: true);

        try {
            $result = $callback();

            $state = $ctx->find(self::CTX_KEY);
            $ctx->set(self::CTX_KEY, array_merge($state, ['deferring' => false]), replace: true);

            foreach ($state['deferred'] as $args) {
                $this->dispatch(...$args);
            }

            return $result;
        } finally {
            $ctx->set(self::CTX_KEY, $prev, replace: true);
        }
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function dispatch($event, $payload = [], $halt = false): ?array
    {
        if ($this->async && $this->shouldDeferForContext($event)) {
            $ctx                 = Context::current();
            $state               = $ctx->find(self::CTX_KEY);
            $state['deferred'][] = func_get_args();
            $ctx->set(self::CTX_KEY, $state, replace: true);

            return null;
        }

        return parent::dispatch($event, $payload, $halt);
    }

    private function shouldDeferForContext(string|object $event): bool
    {
        $state = Context::current()->find(self::CTX_KEY);

        if (!$state || !$state['deferring']) {
            return false;
        }

        if (is_null($state['events'])) {
            return true;
        }

        $eventName = is_object($event) ? $event::class : $event;

        return in_array($eventName, $state['events'], true);
    }
}
