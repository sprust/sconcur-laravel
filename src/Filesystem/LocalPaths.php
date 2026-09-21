<?php

declare(strict_types=1);

namespace SConcur\Laravel\Filesystem;

/**
 * The native checks the Files feature does not make and PHP's own functions do, asked
 * inside a coroutine before an operation goes to the feature — outside one the native
 * call runs anyway, and they would only cost it a stat each.
 */
class LocalPaths
{
    /**
     * Whether both paths name one file — one device and inode, through symlinks and hard
     * links alike. copy() refuses that and writes nothing; the feature would truncate the
     * destination, which is the source, and copy nothing into it.
     */
    public static function isSameFile(string $first, string $second): bool
    {
        clearstatcache(true, $first);
        clearstatcache(true, $second);

        $firstStat  = @stat($first);
        $secondStat = @stat($second);

        return $firstStat !== false
            && $secondStat !== false
            && $firstStat['dev'] === $secondStat['dev']
            && $firstStat['ino'] === $secondStat['ino'];
    }

    /**
     * Whether the path is a regular file, through a symlink too. A directory, a FIFO, a
     * device or a /proc entry is not, and stays with the native call: the feature opens a
     * directory without complaint and fails only when it reads — after it has truncated
     * the destination of a copy — and reads a /proc entry by a size the kernel reports as
     * zero.
     */
    public static function isRegularFile(string $path): bool
    {
        clearstatcache(true, $path);

        return is_file($path);
    }

    /**
     * Whether a write may go to the feature: the path holds a regular file or nothing yet.
     * Anything else there — a directory, a device, a FIFO — is the native call's to answer.
     */
    public static function isRegularOrMissing(string $path): bool
    {
        clearstatcache(true, $path);

        return !file_exists($path) || is_file($path);
    }

    /**
     * Drops PHP's stat and realpath caches after the feature changed the disk.
     *
     * PHP keeps the last stat() and lstat() it made, and file_exists(), is_file(),
     * filesize() and the rest answer from them; the native unlink(), rename() and rmdir()
     * clear them as they go. The feature changes the disk past PHP, so without this an
     * is_dir() of a directory it has just removed would still answer true. Done after every
     * change rather than only where the native call would do it: a stale answer is never
     * the one wanted.
     */
    public static function forgetStats(): void
    {
        clearstatcache(true);
    }

    /** `s3://`, `php://`, `phar://` and the like: only PHP's own wrappers can open them. */
    public static function isStreamWrapperPath(string $path): bool
    {
        return str_contains($path, '://');
    }
}
