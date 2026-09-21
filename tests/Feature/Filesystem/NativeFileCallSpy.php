<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Filesystem;

use Closure;

/**
 * A `file://` stream wrapper that records the native file operations PHP makes on paths
 * under a watched directory, and does each of them natively.
 *
 * PHP sends a plain path through whatever is registered as `file`, so while this is
 * registered fopen() and everything built on it (copy(), file_put_contents(),
 * hash_file()), rename(), unlink(), mkdir(), rmdir(), opendir(), stat() and its family,
 * chmod(), chown() and touch() on a local path pass through here. realpath(), tempnam(),
 * link(), symlink() and readlink() do not. The Files feature does its work inside the extension and
 * passes through nothing — which is what tells a call that went to the feature from one
 * that fell back to the native code.
 *
 * Each operation restores the real wrapper for its own length, so the native function
 * sees a plain filesystem, and a handle it opens keeps working after this is registered
 * again.
 */
class NativeFileCallSpy
{
    protected static string $watchedDirectory = '';

    /** @var list<string> */
    protected static array $calls = [];
    /** @var resource|null set by PHP */
    public mixed $context = null;

    /** @var resource|null */
    protected mixed $handle = null;

    /** @var resource|null */
    protected mixed $directoryHandle = null;

    /**
     * Runs the callback with the spy in place and answers the operations it saw under the
     * directory, as "operation relative/path".
     *
     * @param Closure(): mixed $callback
     *
     * @return list<string>
     */
    public static function watch(string $directory, Closure $callback): array
    {
        self::$watchedDirectory = rtrim($directory, '/') . '/';
        self::$calls            = [];

        stream_wrapper_unregister('file');
        stream_wrapper_register('file', self::class);

        try {
            $callback();
        } finally {
            stream_wrapper_restore('file');
        }

        return self::$calls;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::record(
            operation: 'open',
            path: $path,
        );

        $handle = self::native(static fn(): mixed => @fopen($path, $mode, ($options & STREAM_USE_PATH) !== 0));

        if (!is_resource($handle)) {
            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        assert(is_resource($this->handle));

        return fread($this->handle, max(1, $count));
    }

    public function stream_write(string $data): int
    {
        assert(is_resource($this->handle));

        return (int) fwrite($this->handle, $data);
    }

    public function stream_eof(): bool
    {
        assert(is_resource($this->handle));

        return feof($this->handle);
    }

    public function stream_close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function stream_flush(): bool
    {
        assert(is_resource($this->handle));

        return fflush($this->handle);
    }

    /**
     * @return array<int|string, int>|false
     */
    public function stream_stat(): array|false
    {
        assert(is_resource($this->handle));

        return fstat($this->handle);
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        assert(is_resource($this->handle));

        return fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_tell(): int
    {
        assert(is_resource($this->handle));

        return (int) ftell($this->handle);
    }

    public function stream_lock(int $operation): bool
    {
        assert(is_resource($this->handle));

        assert($operation >= 0 && $operation <= 7);

        return flock($this->handle, $operation);
    }

    public function stream_truncate(int $sizeBytes): bool
    {
        assert(is_resource($this->handle));

        return ftruncate($this->handle, max(0, $sizeBytes));
    }

    public function stream_set_option(int $option, int $firstArgument, ?int $secondArgument): bool
    {
        return false;
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        self::record(
            operation: 'metadata',
            path: $path,
        );

        return self::native(static fn(): bool => match ($option) {
            STREAM_META_TOUCH                         => @touch($path, ...(array) $value),
            STREAM_META_ACCESS                        => @chmod($path, (int) $value),
            STREAM_META_OWNER, STREAM_META_OWNER_NAME => @chown($path, $value),
            STREAM_META_GROUP, STREAM_META_GROUP_NAME => @chgrp($path, $value),
            default                                   => false,
        });
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        self::record(
            operation: 'stat',
            path: $path,
        );

        return self::native(
            static fn(): array|false => ($flags & STREAM_URL_STAT_LINK) !== 0 ? @lstat($path) : @stat($path),
        );
    }

    public function unlink(string $path): bool
    {
        self::record(
            operation: 'unlink',
            path: $path,
        );

        return self::native(static fn(): bool => @unlink($path));
    }

    public function rename(string $from, string $to): bool
    {
        self::record(
            operation: 'rename',
            path: $from,
        );

        return self::native(static fn(): bool => @rename($from, $to));
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        self::record(
            operation: 'mkdir',
            path: $path,
        );

        return self::native(static fn(): bool => @mkdir($path, $mode, ($options & STREAM_MKDIR_RECURSIVE) !== 0));
    }

    public function rmdir(string $path, int $options): bool
    {
        self::record(
            operation: 'rmdir',
            path: $path,
        );

        return self::native(static fn(): bool => @rmdir($path));
    }

    public function dir_opendir(string $path, int $options): bool
    {
        self::record(
            operation: 'opendir',
            path: $path,
        );

        $directoryHandle = self::native(static fn(): mixed => @opendir($path));

        if (!is_resource($directoryHandle)) {
            return false;
        }

        $this->directoryHandle = $directoryHandle;

        return true;
    }

    public function dir_readdir(): string|false
    {
        assert(is_resource($this->directoryHandle));

        return readdir($this->directoryHandle);
    }

    public function dir_rewinddir(): bool
    {
        assert(is_resource($this->directoryHandle));

        rewinddir($this->directoryHandle);

        return true;
    }

    public function dir_closedir(): bool
    {
        if (is_resource($this->directoryHandle)) {
            closedir($this->directoryHandle);
        }

        return true;
    }

    /**
     * @template TResult
     *
     * @param Closure(): TResult $operation
     *
     * @return TResult
     */
    protected static function native(Closure $operation): mixed
    {
        stream_wrapper_restore('file');

        try {
            return $operation();
        } finally {
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', self::class);
        }
    }

    protected static function record(string $operation, string $path): void
    {
        $path = str_starts_with($path, 'file://') ? substr($path, 7) : $path;

        if (str_starts_with($path, self::$watchedDirectory)) {
            self::$calls[] = $operation . ' ' . substr($path, strlen(self::$watchedDirectory));
        }
    }
}
