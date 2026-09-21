<?php

declare(strict_types=1);

namespace SConcur\Laravel\Filesystem;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\UnixVisibility\VisibilityConverter;
use League\MimeTypeDetection\MimeTypeDetector;
use SConcur\Exceptions\Files\FilesException;
use SConcur\Exceptions\Files\FileStoppedException;
use SConcur\Exceptions\Files\FileTimeoutException;
use SConcur\Features\Files\Dto\FileStat;
use SConcur\Features\Files\FileHashAlgorithm;
use SConcur\Features\Files\Files;
use SConcur\Features\Files\FileWriteMode;
use SConcur\Laravel\Support\Coroutine;

/**
 * The adapter of the `sconcur_local` disk: Flysystem's local adapter with the operations
 * where the Files feature pays taken off the PHP thread inside a coroutine. Outside a
 * coroutine every method is the parent's, so the disk is the `local` disk there.
 *
 * On the feature: read, copy, move, delete, deleteDirectory, the metadata calls (one stat
 * instead of a syscall per question), checksum for md5/sha1/sha256/sha512, and write and
 * writeStream when the disk takes no lock (`'lock' => 0`) — the feature has no flock, and
 * a write under LOCK_EX stays the parent's so the disk answers what `local` answers.
 *
 * The parent's: readStream (its contract is a PHP resource), listContents (a feature
 * listing carries no permissions, and every entry needs its visibility), createDirectory,
 * setVisibility and mimeType.
 *
 * A failure on the feature hands the call to the parent, which fails the way it always
 * has — see FilesFeatureCall. writeStream is the exception once it has started reading
 * the stream: that cannot be read twice, so it fails with UnableToWriteFile of its own.
 */
class SconcurLocalFilesystemAdapter extends LocalFilesystemAdapter
{
    public const string DRIVER = 'sconcur_local';

    /** How much of a stream crosses into the extension at a time. */
    protected const int STREAM_CHUNK_BYTES = 1_048_576;

    protected PathPrefixer $pathPrefixer;

    protected VisibilityConverter $visibilityConverter;

    public function __construct(
        string $location,
        ?VisibilityConverter $visibility = null,
        protected int $lockFlags = LOCK_EX,
        int $linkHandling = self::DISALLOW_LINKS,
        ?MimeTypeDetector $mimeTypeDetector = null,
        bool $lazyRootCreation = false,
        bool $useInconclusiveMimeTypeFallback = false,
        protected int $timeoutMs = 0,
    ) {
        // The parent keeps both private; these are built from the same arguments.
        $this->pathPrefixer        = new PathPrefixer($location, DIRECTORY_SEPARATOR);
        $this->visibilityConverter = $visibility ?? new PortableVisibilityConverter();

        parent::__construct(
            location: $location,
            visibility: $this->visibilityConverter,
            writeFlags: $lockFlags,
            linkHandling: $linkHandling,
            mimeTypeDetector: $mimeTypeDetector,
            lazyRootCreation: $lazyRootCreation,
            useInconclusiveMimeTypeFallback: $useInconclusiveMimeTypeFallback,
        );
    }

    public function write(string $path, string $contents, Config $config): void
    {
        if ($this->lockFlags !== 0) {
            parent::write($path, $contents, $config);

            return;
        }

        FilesFeatureCall::run(
            feature: function () use ($path, $contents, $config): void {
                $location = $this->prepareWrite(path: $path, config: $config);

                // 0666 because the umask narrows it, as it narrows file_put_contents().
                Files::write(
                    path: $location,
                    contents: $contents,
                    mode: FileWriteMode::Replace,
                    permissions: 0666,
                    timeoutMs: $this->timeoutMs,
                );

                $this->applyVisibility(path: $path, config: $config);
            },
            native: function () use ($path, $contents, $config): void {
                parent::write($path, $contents, $config);
            },
        );
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        if ($this->lockFlags !== 0 || !Coroutine::isActive()) {
            parent::writeStream($path, $contents, $config);

            return;
        }

        $sourceLocation = $this->plainFileAtStart($contents);

        if ($sourceLocation !== null) {
            // An uploaded file, a file opened by the application: its bytes never have to
            // cross into PHP at all. Nothing has been read yet, so a failure is still the
            // parent's to answer.
            FilesFeatureCall::run(
                feature: function () use ($path, $sourceLocation, $config): void {
                    Files::copy(
                        source: $sourceLocation,
                        destination: $this->prepareWrite(path: $path, config: $config),
                        mode: FileWriteMode::Replace,
                        permissions: 0666,
                        timeoutMs: $this->timeoutMs,
                    );

                    $this->applyVisibility(path: $path, config: $config);
                },
                native: function () use ($path, $contents, $config): void {
                    parent::writeStream($path, $contents, $config);
                },
            );

            return;
        }

        try {
            $fileWriter = Files::openWriter(
                path: $this->prepareWrite(path: $path, config: $config),
                mode: FileWriteMode::Replace,
                permissions: 0666,
                timeoutMs: $this->timeoutMs,
            );

            while (!feof($contents)) {
                $chunk = fread($contents, self::STREAM_CHUNK_BYTES);

                if ($chunk === false) {
                    throw UnableToWriteFile::atLocation($path, 'Could not read from the source stream.');
                }

                if ($chunk !== '') {
                    $fileWriter->write($chunk);
                }
            }

            $fileWriter->close();
        } catch (FileStoppedException|FileTimeoutException $exception) {
            throw $exception;
        } catch (FilesException $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage(), $exception);
        }

        $this->applyVisibility(path: $path, config: $config);
    }

    public function read(string $path): string
    {
        return FilesFeatureCall::run(
            feature: fn(): string => Files::read(
                path: $this->pathPrefixer->prefixPath($path),
                maxReadBytes: 0,
                timeoutMs: $this->timeoutMs,
            ),
            native: fn(): string => parent::read($path),
        );
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $sourceLocation      = $this->pathPrefixer->prefixPath($source);
        $destinationLocation = $this->pathPrefixer->prefixPath($destination);

        // Onto itself the parent copies nothing, where the feature would truncate the source.
        if ($sourceLocation === $destinationLocation) {
            parent::copy($source, $destination, $config);

            return;
        }

        FilesFeatureCall::run(
            feature: function () use ($source, $destination, $destinationLocation, $sourceLocation, $config): void {
                $this->ensureDirectoryExists(
                    dirname($destinationLocation),
                    $this->directoryPermissions($config->get(Config::OPTION_DIRECTORY_VISIBILITY)),
                );

                Files::copy(
                    source: $sourceLocation,
                    destination: $destinationLocation,
                    mode: FileWriteMode::Replace,
                    permissions: 0666,
                    timeoutMs: $this->timeoutMs,
                );

                // The parent's rule: an explicit visibility, else the source's unless
                // retain_visibility is off.
                $visibility = $config->get(
                    Config::OPTION_VISIBILITY,
                    $config->get(Config::OPTION_RETAIN_VISIBILITY, true)
                        ? $this->visibility($source)->visibility()
                        : null,
                );

                if ($visibility) {
                    $this->setVisibility($destination, (string) $visibility);
                }
            },
            native: function () use ($source, $destination, $config): void {
                parent::copy($source, $destination, $config);
            },
        );
    }

    public function move(string $source, string $destination, Config $config): void
    {
        FilesFeatureCall::run(
            feature: function () use ($source, $destination, $config): void {
                $destinationLocation = $this->pathPrefixer->prefixPath($destination);

                $this->ensureDirectoryExists(
                    dirname($destinationLocation),
                    $this->directoryPermissions($config->get(Config::OPTION_DIRECTORY_VISIBILITY)),
                );

                Files::move(
                    source: $this->pathPrefixer->prefixPath($source),
                    destination: $destinationLocation,
                    timeoutMs: $this->timeoutMs,
                );

                $this->applyVisibility(path: $destination, config: $config);
            },
            native: function () use ($source, $destination, $config): void {
                parent::move($source, $destination, $config);
            },
        );
    }

    public function delete(string $path): void
    {
        FilesFeatureCall::run(
            feature: function () use ($path): void {
                Files::delete(
                    path: $this->pathPrefixer->prefixPath($path),
                    missingOk: true,
                    timeoutMs: $this->timeoutMs,
                );
            },
            native: function () use ($path): void {
                parent::delete($path);
            },
        );
    }

    public function deleteDirectory(string $prefix): void
    {
        FilesFeatureCall::run(
            feature: function () use ($prefix): void {
                Files::removeDirectory(
                    path: $this->pathPrefixer->prefixPath($prefix),
                    recursive: true,
                    missingOk: true,
                    timeoutMs: $this->timeoutMs,
                );
            },
            native: function () use ($prefix): void {
                parent::deleteDirectory($prefix);
            },
        );
    }

    public function fileExists(string $location): bool
    {
        return FilesFeatureCall::run(
            feature: fn(): bool => $this->stat($location)->isFile,
            native: fn(): bool => parent::fileExists($location),
        );
    }

    public function directoryExists(string $location): bool
    {
        return FilesFeatureCall::run(
            feature: fn(): bool => $this->stat($location)->isDirectory,
            native: fn(): bool => parent::directoryExists($location),
        );
    }

    public function fileSize(string $path): FileAttributes
    {
        return FilesFeatureCall::run(
            feature: function () use ($path): FileAttributes {
                $fileStat = $this->stat($path);

                // Anything but a file is the parent's to refuse, with its own message.
                return $fileStat->isFile
                    ? new FileAttributes($path, $fileStat->sizeBytes)
                    : parent::fileSize($path);
            },
            native: fn(): FileAttributes => parent::fileSize($path),
        );
    }

    public function lastModified(string $path): FileAttributes
    {
        return FilesFeatureCall::run(
            feature: function () use ($path): FileAttributes {
                $fileStat = $this->stat($path);

                // filemtime() answers whole seconds, rounded down.
                return $fileStat->exists
                    ? new FileAttributes($path, null, null, (int) floor($fileStat->modifiedAtMs / 1000))
                    : parent::lastModified($path);
            },
            native: fn(): FileAttributes => parent::lastModified($path),
        );
    }

    public function visibility(string $path): FileAttributes
    {
        return FilesFeatureCall::run(
            feature: function () use ($path): FileAttributes {
                $fileStat = $this->stat($path);

                // The parent reads a directory through inverseForFile() too.
                return $fileStat->exists
                    ? new FileAttributes(
                        $path,
                        null,
                        $this->visibilityConverter->inverseForFile($fileStat->permissions & 0777),
                    )
                    : parent::visibility($path);
            },
            native: fn(): FileAttributes => parent::visibility($path),
        );
    }

    public function checksum(string $path, Config $config): string
    {
        $fileHashAlgorithm = FileHashAlgorithm::tryFrom(strtolower((string) $config->get('checksum_algo', 'md5')));

        if ($fileHashAlgorithm === null) {
            return parent::checksum($path, $config);
        }

        return FilesFeatureCall::run(
            feature: fn(): string => Files::hashFile(
                path: $this->pathPrefixer->prefixPath($path),
                algorithm: $fileHashAlgorithm,
                timeoutMs: $this->timeoutMs,
            ),
            native: fn(): string => parent::checksum($path, $config),
        );
    }

    protected function stat(string $path): FileStat
    {
        return Files::stat(
            path: $this->pathPrefixer->prefixPath($path),
            timeoutMs: $this->timeoutMs,
        );
    }

    /**
     * What the parent does before it writes: the directory the file goes into, with the
     * directory visibility the call asks for. Answers the location to write to.
     */
    protected function prepareWrite(string $path, Config $config): string
    {
        $location = $this->pathPrefixer->prefixPath($path);

        $this->ensureDirectoryExists(
            dirname($location),
            $this->directoryPermissions($config->get(Config::OPTION_DIRECTORY_VISIBILITY)),
        );

        return $location;
    }

    /** What the parent does after it writes or moves: the visibility the call asks for. */
    protected function applyVisibility(string $path, Config $config): void
    {
        $visibility = $config->get(Config::OPTION_VISIBILITY);

        if ($visibility) {
            $this->setVisibility($path, (string) $visibility);
        }
    }

    /** The parent's resolveDirectoryVisibility(), which is private to it. */
    protected function directoryPermissions(mixed $visibility): int
    {
        return $visibility === null
            ? $this->visibilityConverter->defaultForDirectories()
            : $this->visibilityConverter->forDirectory((string) $visibility);
    }

    /**
     * The path of a local file the stream reads from its very start, or null when it is
     * anything else — a socket, php://temp, a stream somebody has already read from.
     *
     * @param resource $contents
     */
    protected function plainFileAtStart(mixed $contents): ?string
    {
        $metadata = stream_get_meta_data($contents);

        if ($metadata['wrapper_type'] !== 'plainfile' || !$metadata['seekable'] || ftell($contents) !== 0) {
            return null;
        }

        $uri = $metadata['uri'] ?? '';

        return is_file($uri) ? $uri : null;
    }
}
