<?php

declare(strict_types=1);

namespace SConcur\Laravel\Filesystem;

use Illuminate\Filesystem\Filesystem as IlluminateFilesystem;
use SConcur\Features\Files\FileHashAlgorithm;
use SConcur\Features\Files\Files;
use SConcur\Features\Files\FileWriteMode;

/**
 * The `files` binding (the File facade) with the operations where the Files feature pays
 * taken off the PHP thread inside a coroutine: copy and move, whose bytes never cross into
 * PHP, hashing, whose read loop runs on the runtime, and replace, which is the feature's
 * atomic write. Everything else is the framework's own.
 *
 * Deliberately not here: the metadata calls (exists, lastModified, …), which the view
 * finder, the Blade compiler and the translator make on every request and which a boundary
 * crossing would only slow down; everything that takes a lock, which the feature does not
 * have; getRequire/requireOnce, which are `include`; and the Finder-based listings, whose
 * contract is Symfony's SplFileInfo.
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

        if (!$this->isFeaturePair(path: $path, target: $target)) {
            return parent::copy($path, $target);
        }

        return FilesFeatureCall::run(
            feature: function () use ($path, $target): bool {
                // copy() onto the same file — through a symlink or a hard link — refuses
                // and writes nothing; the feature would truncate the source it reads.
                if (LocalPaths::isSameFile(first: $path, second: $target)) {
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

    public function move($path, $target)
    {
        $path   = (string) $path;
        $target = (string) $target;

        if (!$this->isFeaturePair(path: $path, target: $target)) {
            return parent::move($path, $target);
        }

        return FilesFeatureCall::run(
            feature: function () use ($path, $target): bool {
                if (
                    LocalPaths::isSameFile(first: $path, second: $target)
                    || LocalPaths::crossesDevices(source: $path, target: $target)
                ) {
                    return parent::move($path, $target);
                }

                Files::move(
                    source: $path,
                    destination: $target,
                    timeoutMs: $this->timeoutMs,
                );

                LocalPaths::forgetStats();

                return true;
            },
            native: fn(): bool => parent::move($path, $target),
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
            feature: fn(): string => Files::hashFile(
                path: $path,
                algorithm: $fileHashAlgorithm,
                timeoutMs: $this->timeoutMs,
            ),
            native: fn(): string|false => parent::hash($path, $algorithm),
        );
    }

    /**
     * @param string|resource|array<array-key, mixed> $content what file_put_contents() takes
     */
    public function replace($path, $content, $mode = null)
    {
        $path = (string) $path;

        // file_put_contents() takes a resource or an array as well; those stay the parent's.
        if (!is_string($content)) {
            // @phpstan-ignore argument.type (the parent's PHPDoc says string; file_put_contents() takes this too)
            parent::replace($path, $content, $mode);

            return;
        }

        // The parent's default: tempnam() creates the file 0600 and it chmods it to this.
        $permissions = $mode ?? (0777 - umask());

        // 0 is "keep the target's bits" to the feature and chmod 000 to the parent.
        if ($permissions === 0 || LocalPaths::isStreamWrapperPath($path)) {
            parent::replace($path, $content, $mode);

            return;
        }

        FilesFeatureCall::run(
            feature: function () use ($path, $content, $permissions): void {
                // The parent writes through a symlink rather than over it, and so does this.
                clearstatcache(true, $path);

                Files::writeAtomic(
                    path: realpath($path) ?: $path,
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

    /**
     * Both paths are local files the feature can reach, and not literally one path. The
     * checks that need a stat — one file under two names, two filesystems — are made inside
     * the coroutine, where the feature would otherwise run.
     */
    protected function isFeaturePair(string $path, string $target): bool
    {
        return !LocalPaths::isStreamWrapperPath($path)
            && !LocalPaths::isStreamWrapperPath($target)
            && $path !== $target;
    }
}
