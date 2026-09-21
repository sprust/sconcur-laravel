<?php

declare(strict_types=1);

namespace SConcur\Laravel\Filesystem;

/**
 * The native checks the Files feature does not make and PHP's own copy() and rename() do,
 * asked inside a coroutine before an operation goes to the feature — outside one the
 * native call runs anyway, and they would only cost it a stat.
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
     * Whether a move of the source to the target crosses filesystems. False when it cannot
     * be told — a missing source or target directory — and the move is left to fail where
     * it runs.
     *
     * rename() does such a move as a copy through the target path — into the file an
     * existing symlink there points at, onto an existing inode, with the source's mode and
     * owner — and the feature as a copy to a temporary file renamed over the target. A copy
     * either way, so there is little to gain, and it stays native.
     */
    public static function crossesDevices(string $source, string $target): bool
    {
        clearstatcache(true, $source);

        $sourceStat    = @stat($source);
        $directoryStat = @stat(dirname($target));

        return $sourceStat !== false
            && $directoryStat !== false
            && $sourceStat['dev'] !== $directoryStat['dev'];
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
