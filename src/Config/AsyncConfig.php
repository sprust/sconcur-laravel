<?php

declare(strict_types=1);

namespace SConcur\Laravel\Config;

use Illuminate\Config\Repository;
use Illuminate\Support\Arr;
use SConcur\Context\Context;

/**
 * Coroutine-safe config repository.
 *
 * Before bootCompleted(): behaves like the stock Repository (writes to $items).
 * After bootCompleted(): set() writes to a per-coroutine overlay in the context;
 * get() reads the overlay first, then the shared base $items.
 *
 * Base $items are read-only after boot — shared across all coroutines.
 *
 * Ported from yangusik/laravel-spawn (AsyncConfig), backed by SConcur\Context\Context.
 */
class AsyncConfig extends Repository
{
    private const string CTX_KEY = 'config.overlay';

    private bool $async = false;

    public function bootCompleted(): void
    {
        $this->async = true;
    }

    public function set($key, $value = null): void
    {
        if (!$this->async) {
            parent::set($key, $value);

            return;
        }

        $ctx     = Context::current();
        $overlay = $ctx->find(self::CTX_KEY) ?? [];

        $keys = is_array($key) ? $key : [$key => $value];

        foreach ($keys as $k => $v) {
            Arr::set($overlay, $k, $v);
        }

        $ctx->set(self::CTX_KEY, $overlay, replace: true);
    }

    public function get($key, $default = null)
    {
        if (!$this->async) {
            return parent::get($key, $default);
        }

        if (is_array($key)) {
            return $this->getMany($key);
        }

        $overlay = Context::current()->find(self::CTX_KEY);

        if ($overlay !== null && Arr::has($overlay, $key)) {
            return Arr::get($overlay, $key);
        }

        return Arr::get($this->items, $key, $default);
    }

    public function getMany($keys): array
    {
        $config = [];

        foreach ($keys as $key => $default) {
            if (is_numeric($key)) {
                [$key, $default] = [$default, null];
            }

            $config[$key] = $this->get($key, $default);
        }

        return $config;
    }

    public function has($key): bool
    {
        if (!$this->async) {
            return parent::has($key);
        }

        $overlay = Context::current()->find(self::CTX_KEY);

        if ($overlay !== null && Arr::has($overlay, $key)) {
            return true;
        }

        return Arr::has($this->items, $key);
    }

    public function all(): array
    {
        if (!$this->async) {
            return parent::all();
        }

        $overlay = Context::current()->find(self::CTX_KEY) ?? [];

        return array_replace_recursive($this->items, $overlay);
    }
}
