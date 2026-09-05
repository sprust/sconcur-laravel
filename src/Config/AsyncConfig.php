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

    /**
     * The exact keys the overlay was written under.
     *
     * Needed because the overlay alone cannot say whether `sconcur.ws` was replaced or
     * merely has something of its own underneath it. Without the distinction, reading a
     * parent of an overlaid key answered with the overlaid branch alone and dropped every
     * sibling the base had — `config()->set('app.locale', 'x')` made `config('app')` a
     * one-element array.
     */
    private const string CTX_PATHS_KEY = 'config.overlay.paths';

    private bool $async = false;

    public function bootCompleted(): void
    {
        $this->async = true;
    }

    /**
     * @param array<string, mixed>|string $key
     */
    public function set($key, $value = null): void
    {
        if (!$this->async) {
            parent::set($key, $value);

            return;
        }

        $ctx     = Context::current();
        $overlay = $ctx->find(self::CTX_KEY) ?? [];
        $paths   = $ctx->find(self::CTX_PATHS_KEY) ?? [];

        $keys = is_array($key) ? $key : [$key => $value];

        foreach ($keys as $k => $v) {
            Arr::set($overlay, $k, $v);

            $paths[$k] = true;
        }

        $ctx->set(self::CTX_KEY, $overlay, replace: true);
        $ctx->set(self::CTX_PATHS_KEY, $paths, replace: true);
    }

    /**
     * @param list<string>|string $key
     */
    public function get($key, $default = null)
    {
        if (!$this->async) {
            return parent::get($key, $default);
        }

        if (is_array($key)) {
            return $this->getMany($key);
        }

        $ctx = Context::current();

        $overlay = $ctx->find(self::CTX_KEY);

        if ($overlay === null || !Arr::has($overlay, $key)) {
            return Arr::get($this->items, $key, $default);
        }

        $paths = $ctx->find(self::CTX_PATHS_KEY) ?? [];

        // Written under exactly this key: it replaces the base, list values included.
        if (isset($paths[$key])) {
            return Arr::get($overlay, $key);
        }

        $base = Arr::get($this->items, $key, $default);

        if (!is_array($base)) {
            return Arr::get($overlay, $key);
        }

        // Only branches below it were written. Each is laid onto a copy of the base where
        // it belongs, one at a time, rather than merged wholesale: a merge keeps whatever
        // the base had beyond the end of a written list, so setting a list to [] would
        // hand back the base's list unchanged.
        $prefix = $key . '.';

        $merged = $base;

        foreach (array_keys($paths) as $path) {
            if (!str_starts_with((string) $path, $prefix)) {
                continue;
            }

            Arr::set($merged, substr((string) $path, strlen($prefix)), Arr::get($overlay, $path));
        }

        return $merged;
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

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if (!$this->async) {
            return parent::all();
        }

        $overlay = Context::current()->find(self::CTX_KEY) ?? [];

        return array_replace_recursive($this->items, $overlay);
    }
}
