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
        if (!$this->isLocalPair(path: $path, target: $target)) {
            return parent::copy($path, $target);
        }

        return FilesFeatureCall::run(
            feature: function () use ($path, $target): bool {
                // 0666 because the umask narrows it, as it narrows the mode copy() creates
                // a file with; an existing target keeps its own bits either way.
                Files::copy(
                    source: $path,
                    destination: $target,
                    mode: FileWriteMode::Replace,
                    permissions: 0666,
                    timeoutMs: $this->timeoutMs,
                );

                return true;
            },
            native: fn(): bool => parent::copy($path, $target),
        );
    }

    public function move($path, $target)
    {
        if (!$this->isLocalPair(path: $path, target: $target)) {
            return parent::move($path, $target);
        }

        return FilesFeatureCall::run(
            feature: function () use ($path, $target): bool {
                Files::move(
                    source: $path,
                    destination: $target,
                    timeoutMs: $this->timeoutMs,
                );

                return true;
            },
            native: fn(): bool => parent::move($path, $target),
        );
    }

    public function hash($path, $algorithm = 'md5')
    {
        $fileHashAlgorithm = FileHashAlgorithm::tryFrom(strtolower((string) $algorithm));

        if ($fileHashAlgorithm === null || $this->isStreamWrapperPath($path)) {
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

    public function replace($path, $content, $mode = null)
    {
        // The parent's default: tempnam() creates the file 0600 and it chmods it to this.
        $permissions = $mode ?? (0777 - umask());

        // 0 is "keep the target's bits" to the feature and chmod 000 to the parent.
        if ($permissions === 0 || $this->isStreamWrapperPath($path)) {
            parent::replace($path, $content, $mode);

            return;
        }

        FilesFeatureCall::run(
            feature: function () use ($path, $content, $permissions): void {
                // The parent writes through a symlink rather than over it, and so does this.
                clearstatcache(true, $path);

                Files::writeAtomic(
                    path: realpath($path) ?: $path,
                    contents: (string) $content,
                    permissions: $permissions,
                    timeoutMs: $this->timeoutMs,
                );
            },
            native: function () use ($path, $content, $mode): void {
                parent::replace($path, $content, $mode);
            },
        );
    }

    /**
     * Both paths are local files the feature can reach, and not the same file: copy() onto
     * itself is the parent's to answer, where the feature would truncate the source it is
     * about to read.
     */
    protected function isLocalPair(string $path, string $target): bool
    {
        if ($this->isStreamWrapperPath($path) || $this->isStreamWrapperPath($target)) {
            return false;
        }

        if ($path === $target) {
            return false;
        }

        $realPath   = realpath($path);
        $realTarget = realpath($target);

        return $realPath === false || $realTarget === false || $realPath !== $realTarget;
    }

    /** `s3://`, `php://`, `phar://` and the like: only PHP's own wrappers can open them. */
    protected function isStreamWrapperPath(string $path): bool
    {
        return str_contains($path, '://');
    }
}
