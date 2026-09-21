<?php

declare(strict_types=1);

namespace SConcur\Laravel\Filesystem;

use Illuminate\Filesystem\Filesystem as IlluminateFilesystem;
use SConcur\Features\Files\FileHashAlgorithm;
use SConcur\Features\Files\Files;
use SConcur\Features\Files\FileWriteMode;

/**
 * The `files` binding (the File facade) with the operations where the Files feature pays
 * taken off the PHP thread inside a coroutine: copy, whose bytes never cross into PHP,
 * hashing, whose read loop runs on the runtime, and replace, which is the feature's atomic
 * write. Everything else is the framework's own.
 *
 * Deliberately not here: move, which within one filesystem is one rename(2) with nothing
 * to gain and across filesystems a copy the native call does differently; the metadata
 * calls (exists, lastModified, …), which the view finder, the Blade compiler and the
 * translator make on every request and which a boundary crossing would only slow down;
 * everything that takes a lock, which the feature does not have; getRequire/requireOnce,
 * which are `include`; and the Finder-based listings, whose contract is Symfony's
 * SplFileInfo.
 *
 * Every override answers exactly as the parent does, failures included: see
 * FilesFeatureCall.
 */
class Filesystem extends IlluminateFilesystem
{
    public function __construct(
        protected int $timeoutMs = 0,
    ) {
    }

    public function copy($path, $target)
    {
        // The parent takes a Stringable path (SplFileInfo, UploadedFile) as well.
        $path   = (string) $path;
        $target = (string) $target;

        if (LocalPaths::isStreamWrapperPath($path) || LocalPaths::isStreamWrapperPath($target) || $path === $target) {
            return parent::copy($path, $target);
        }

        return FilesFeatureCall::run(
            feature: function () use ($path, $target): bool {
                // A source that is not a regular file, a target that is something else, and
                // one file under two names (copy() refuses that and writes nothing, the
                // feature would truncate the source it reads) are the parent's.
                if (
                    !LocalPaths::isRegularFile($path)
                    || !LocalPaths::isRegularOrMissing($target)
                    || LocalPaths::isSameFile(first: $path, second: $target)
                ) {
                    return parent::copy($path, $target);
                }

                // 0666 because the umask narrows it, as it narrows the mode copy() creates
                // a file with; an existing target keeps its own bits either way.
                Files::copy(
                    source: $path,
                    destination: $target,
                    mode: FileWriteMode::Replace,
                    permissions: 0666,
                    timeoutMs: $this->timeoutMs,
                );

                LocalPaths::forgetStats();

                return true;
            },
            native: fn(): bool => parent::copy($path, $target),
        );
    }

    public function hash($path, $algorithm = 'md5')
    {
        $path = (string) $path;

        $fileHashAlgorithm = FileHashAlgorithm::tryFrom(strtolower((string) $algorithm));

        if ($fileHashAlgorithm === null || LocalPaths::isStreamWrapperPath($path)) {
            return parent::hash($path, $algorithm);
        }

        return FilesFeatureCall::run(
            feature: fn(): string|false => LocalPaths::isRegularFile($path)
                ? Files::hashFile(
                    path: $path,
                    algorithm: $fileHashAlgorithm,
                    timeoutMs: $this->timeoutMs,
                )
                : parent::hash($path, $algorithm),
            native: fn(): string|false => parent::hash($path, $algorithm),
        );
    }

    /**
     * @param string|resource|array<array-key, mixed> $content what file_put_contents() takes
     * @param int|string|null                         $mode    what chmod() takes, a numeric
     *                                                         string included
     */
    public function replace($path, $content, $mode = null)
    {
        $path = (string) $path;

        // The parent's default: tempnam() creates the file 0600 and it chmods it to this.
        $permissions = $mode ?? (0777 - umask());

        // Stay the parent's: contents file_put_contents() takes besides a string (a
        // resource, an array); a mode that is not plain permission bits — 0, which the
        // feature reads as "keep the target's", setuid/setgid/sticky, which the parent's
        // write after its chmod clears, type bits from fileperms(), a string; and a stream
        // wrapper path.
        if (
            !is_string($content)
            || !is_int($permissions)
            || $permissions < 1
            || $permissions > 0777
            || LocalPaths::isStreamWrapperPath($path)
        ) {
            // The parent's PHPDoc says a string and an int; file_put_contents() and chmod() take these too.
            // @phpstan-ignore argument.type, argument.type
            parent::replace($path, $content, $mode);

            return;
        }

        // Past the check above the mode is an int or the default.
        $mode = is_int($mode) ? $mode : null;

        FilesFeatureCall::run(
            feature: function () use ($path, $content, $mode, $permissions): void {
                // The parent writes through a symlink rather than over it, and so does this.
                clearstatcache(true, $path);

                $target = realpath($path) ?: $path;

                if (!LocalPaths::isRegularOrMissing($target)) {
                    parent::replace($path, $content, $mode);

                    return;
                }

                Files::writeAtomic(
                    path: $target,
                    contents: $content,
                    permissions: $permissions,
                    timeoutMs: $this->timeoutMs,
                );

                LocalPaths::forgetStats();
            },
            native: function () use ($path, $content, $mode): void {
                parent::replace($path, $content, $mode);
            },
        );
    }
}
