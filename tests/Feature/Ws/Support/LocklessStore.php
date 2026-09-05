<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws\Support;

use Illuminate\Contracts\Cache\Store;

/**
 * A cache store with no locks and, optionally, no working connection.
 *
 * Both matter to the presence repository: a store that cannot lock takes the unlocked
 * path — right, because two workers cannot share such a store anyway — and a store that
 * throws must not take the caller's teardown with it.
 */
class LocklessStore implements Store
{
    public bool $down = false;

    /** @var array<string, mixed> */
    private array $items = [];

    public function get($key): mixed
    {
        $this->guard();

        return $this->items[$key] ?? null;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        return array_map($this->get(...), array_combine($keys, $keys));
    }

    public function put($key, $value, $seconds): bool
    {
        $this->guard();

        $this->items[$key] = $value;

        return true;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function putMany(array $values, $seconds): bool
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    public function increment($key, $value = 1): int
    {
        return (int) $value;
    }

    public function decrement($key, $value = 1): int
    {
        return (int) -$value;
    }

    public function forever($key, $value): bool
    {
        return $this->put($key, $value, 0);
    }

    public function forget($key): bool
    {
        $this->guard();

        unset($this->items[$key]);

        return true;
    }

    public function flush(): bool
    {
        $this->items = [];

        return true;
    }

    public function getPrefix(): string
    {
        return '';
    }

    private function guard(): void
    {
        if ($this->down) {
            throw new StoreIsDownException('cache store is unreachable');
        }
    }
}
