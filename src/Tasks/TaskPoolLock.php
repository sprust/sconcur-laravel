<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tasks;

/**
 * Keeps a second pool from running beside the first, so that a copy started by hand
 * cannot tick `schedule:run` a second time in the same minute.
 *
 * flock rather than a cache lock, for the same reason the library's master uses it: the
 * kernel drops the lock when the process dies, SIGKILL included, so there is no stale
 * lock to reason about and no TTL to keep refreshing.
 */
class TaskPoolLock
{
    /** @var resource|null */
    protected mixed $handle = null;

    /** Why the last acquire() failed, for a caller that has to explain it. */
    protected string $failure = '';

    public function __construct(protected string $path)
    {
    }

    public function acquire(): bool
    {
        $directory = dirname($this->path);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            return $this->failed('cannot create ' . $directory);
        }

        $handle = @fopen($this->path, 'c');

        if ($handle === false) {
            // Told apart from a held lock deliberately: an unwritable path reported as
            // "another pool holds it" would have the supervisor restarting for ever while
            // every log line blamed a pool that does not exist.
            return $this->failed('cannot open ' . $this->path);
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return $this->failed('another process holds ' . $this->path);
        }

        $this->handle  = $handle;
        $this->failure = '';

        return true;
    }

    /** What went wrong the last time acquire() said no. */
    public function failure(): string
    {
        return $this->failure;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);

        $this->handle = null;
    }

    public function path(): string
    {
        return $this->path;
    }

    protected function failed(string $reason): bool
    {
        $this->failure = $reason;

        return false;
    }
}
