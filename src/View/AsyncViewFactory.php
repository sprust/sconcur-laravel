<?php

declare(strict_types=1);

namespace SConcur\Laravel\View;

use Illuminate\View\Factory;
use SConcur\Context\Context;

/**
 * Coroutine-safe view factory.
 *
 * Before bootCompleted(): share() writes to parent::$shared (boot-time data).
 * After bootCompleted(): share() writes only to the per-coroutine context;
 * getShared() merges boot-time $shared with the per-coroutine overrides.
 *
 * Ported from yangusik/laravel-spawn (AsyncViewFactory).
 */
class AsyncViewFactory extends Factory
{
    private const string CTX_KEY = 'view.shared';

    private bool $async = false;

    public function bootCompleted(): void
    {
        $this->async = true;
    }

    public function share($key, $value = null)
    {
        if (!$this->async) {
            return parent::share($key, $value);
        }

        $keys = is_array($key) ? $key : [$key => $value];

        $ctx    = Context::current();
        $shared = $ctx->find(self::CTX_KEY);

        if ($shared === null) {
            $ctx->set(self::CTX_KEY, $keys);
        } else {
            foreach ($keys as $k => $v) {
                $shared[$k] = $v;
            }

            $ctx->set(self::CTX_KEY, $shared, replace: true);
        }

        return $value;
    }

    public function getShared()
    {
        if (!$this->async) {
            return parent::getShared();
        }

        $perRequest = Context::current()->find(self::CTX_KEY) ?? [];

        return array_merge($this->shared, $perRequest);
    }
}
