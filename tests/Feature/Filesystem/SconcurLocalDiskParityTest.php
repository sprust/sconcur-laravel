<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Filesystem;

use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Laravel\Filesystem\SconcurLocalFilesystemAdapter;

/**
 * A `sconcur_local` disk answers exactly as a `local` disk with the same config: every
 * case runs through both, inside a coroutine where the feature is used and outside one
 * where it is not, with `throw` on so a failure is compared as the exception it raises.
 */
class SconcurLocalDiskParityTest extends BaseFilesystemTestCase
{
    /**
     * @param array<string, mixed>                      $config
     * @param Closure(string): void                     $arrange
     * @param Closure(FilesystemAdapter, string): mixed $act
     */
    #[Test]
    #[DataProvider('cases')]
    public function itAnswersLikeALocalDisk(array $config, Closure $arrange, Closure $act): void
    {
        foreach ([true, false] as $inCoroutine) {
            $native = $this->outcome(
                arrange: $arrange,
                act: fn(): mixed => $act($this->disk(driver: 'local', config: $config), $this->root),
                inCoroutine: $inCoroutine,
            );

            $sconcur = $this->outcome(
                arrange: $arrange,
                act: fn(): mixed => $act(
                    $this->disk(driver: SconcurLocalFilesystemAdapter::DRIVER, config: $config),
                    $this->root,
                ),
                inCoroutine: $inCoroutine,
            );

            self::assertSame($native, $sconcur, $inCoroutine ? 'in a coroutine' : 'outside a coroutine');
        }
    }

    #[Test]
    public function aChecksumDoesNotHoldTheProcess(): void
    {
        $this->largeFile($this->root . '/large.bin');

        $local   = $this->disk(driver: 'local', config: []);
        $sconcur = $this->disk(driver: SconcurLocalFilesystemAdapter::DRIVER, config: []);

        $nativeStallMs = $this->longestStallMs([
            static function () use ($local): void {
                // Suspended first, so the ticker is running by the time the hash starts.
                Sleeper::usleep(30_000);

                $local->checksum('large.bin', ['checksum_algo' => 'md5']);
            },
        ]);

        $featureStallMs = $this->longestStallMs([
            static function () use ($sconcur): void {
                Sleeper::usleep(30_000);

                $sconcur->checksum('large.bin', ['checksum_algo' => 'md5']);
            },
        ]);

        // md5 rather than sha256, whose hardware instructions leave too short a native stall
        // to tell apart from a tick; the feature's bound is absolute, a few ticks.
        self::assertGreaterThan(60.0, $nativeStallMs, 'the measurement does not see a native hash');
        self::assertLessThan(40.0, $featureStallMs);
    }

    /**
     * The options whose support lives outside this disk are refused when it is built, not
     * quietly ignored.
     */
    #[Test]
    #[DataProvider('refusedOptions')]
    public function itRefusesWhatItWouldNotHonour(string $key, mixed $value): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("does not support `$key`");

        $this->disk(driver: SconcurLocalFilesystemAdapter::DRIVER, config: [$key => $value]);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function refusedOptions(): array
    {
        return [
            'serve'     => ['serve', true],
            'read-only' => ['read-only', true],
            'prefix'    => ['prefix', 'nested'],
        ];
    }

    /**
     * @return array<string, array{
     *     array<string, mixed>,
     *     Closure(string): void,
     *     Closure(FilesystemAdapter, string): mixed,
     * }>
     */
    public static function cases(): array
    {
        $unlocked = ['lock' => 0];

        $nothing = static function (string $root): void {
        };

        $file = static function (string $root): void {
            file_put_contents($root . '/file.txt', 'contents');
            chmod($root . '/file.txt', 0600);
        };

        $tree = static function (string $root): void {
            mkdir($root . '/directory/nested', 0755, true);
            file_put_contents($root . '/directory/file.txt', 'contents');
            file_put_contents($root . '/directory/nested/file.txt', 'contents');
            file_put_contents($root . '/file.txt', 'contents');
        };

        $links = static function (string $root): void {
            mkdir($root . '/directory');
            file_put_contents($root . '/directory/file.txt', 'contents');
            file_put_contents($root . '/file.txt', 'contents');
            symlink($root . '/file.txt', $root . '/link.txt');
            symlink($root . '/missing.txt', $root . '/dangling.txt');
            symlink($root . '/directory', $root . '/linked-directory');
            link($root . '/file.txt', $root . '/hard.txt');
        };

        return [
            'put a new file' => [
                $unlocked,
                $nothing,
                static fn(FilesystemAdapter $disk): mixed => $disk->put('new.txt', 'contents'),
            ],
            'put a new file under the default lock' => [
                [],
                $nothing,
                static fn(FilesystemAdapter $disk): mixed => $disk->put('new.txt', 'contents'),
            ],
            'put over a file keeps its permissions' => [
                $unlocked,
                $file,
                static fn(FilesystemAdapter $disk): mixed => $disk->put('file.txt', 'new'),
            ],
            'put into missing directories' => [
                $unlocked,
                $nothing,
                static fn(FilesystemAdapter $disk): mixed => $disk->put('a/b/new.txt', 'contents'),
            ],
            'put with a visibility' => [
                $unlocked,
                $nothing,
                static fn(FilesystemAdapter $disk): mixed => $disk->put('new.txt', 'contents', 'public'),
            ],
            'put onto a directory' => [
                $unlocked,
                $tree,
                static fn(FilesystemAdapter $disk): mixed => $disk->put('directory', 'contents'),
            ],
            'write a stream of a local file' => [
                $unlocked,
                $file,
                static function (FilesystemAdapter $disk, string $root): mixed {
                    $stream = fopen($root . '/file.txt', 'rb');

                    assert($stream !== false);

                    return $disk->writeStream('streamed/copy.txt', $stream);
                },
            ],
            'write a stream the caller has read from, which Flysystem rewinds' => [
                $unlocked,
                $file,
                static function (FilesystemAdapter $disk, string $root): mixed {
                    $stream = fopen($root . '/file.txt', 'rb');

                    assert($stream !== false);

                    fread($stream, 3);

                    return $disk->writeStream('copy.txt', $stream);
                },
            ],
            'write a stream held in memory' => [
                $unlocked,
                $nothing,
                static function (FilesystemAdapter $disk): mixed {
                    $stream = fopen('php://temp', 'w+b');

                    assert($stream !== false);

                    fwrite($stream, str_repeat('0123456789', 300_000));
                    rewind($stream);

                    return $disk->writeStream('memory.txt', $stream, ['visibility' => 'public']);
                },
            ],
            'write a filtered stream' => [
                $unlocked,
                $file,
                static function (FilesystemAdapter $disk, string $root): mixed {
                    $stream = fopen($root . '/file.txt', 'rb');

                    assert($stream !== false);

                    stream_filter_append($stream, 'string.toupper');

                    return [
                        $disk->writeStream('upper.txt', $stream),
                        ftell($stream),
                    ];
                },
            ],
            'write a stream that cannot be read' => [
                $unlocked,
                $file,
                static function (FilesystemAdapter $disk, string $root): mixed {
                    $stream = fopen($root . '/sink.txt', 'wb');

                    assert($stream !== false);

                    return $disk->writeStream('copy.txt', $stream);
                },
            ],
            'write a stream onto a directory' => [
                $unlocked,
                $tree,
                static function (FilesystemAdapter $disk): mixed {
                    $stream = fopen('php://temp', 'w+b');

                    assert($stream !== false);

                    fwrite($stream, 'contents');
                    rewind($stream);

                    return $disk->writeStream('directory', $stream);
                },
            ],
            'get a file' => [
                $unlocked,
                $file,
                static fn(FilesystemAdapter $disk): mixed => $disk->get('file.txt'),
            ],
            'get a missing file' => [
                $unlocked,
                $nothing,
                static fn(FilesystemAdapter $disk): mixed => $disk->get('missing.txt'),
            ],
            'get a directory' => [
                $unlocked,
                $tree,
                static fn(FilesystemAdapter $disk): mixed => $disk->get('directory'),
            ],
            'copy a file keeps the source visibility' => [
                $unlocked,
                $file,
                static fn(FilesystemAdapter $disk): mixed => $disk->copy('file.txt', 'copies/copy.txt'),
            ],
            'copy a file onto itself' => [
                $unlocked,
                $file,
                static fn(FilesystemAdapter $disk): mixed => $disk->copy('file.txt', 'file.txt'),
            ],
            'copy a missing file' => [
                $unlocked,
                $nothing,
                static fn(FilesystemAdapter $disk): mixed => $disk->copy('missing.txt', 'copy.txt'),
            ],
            'copy onto itself through a symlink' => [
                $unlocked,
                $links,
                static fn(FilesystemAdapter $disk): mixed => $disk->copy('link.txt', 'file.txt'),
            ],
            'copy onto itself through a hard link' => [
                $unlocked,
                $links,
                static fn(FilesystemAdapter $disk): mixed => $disk->copy('file.txt', 'hard.txt'),
            ],
            'move onto itself through a hard link' => [
                $unlocked,
                $links,
                static fn(FilesystemAdapter $disk): mixed => $disk->move('file.txt', 'hard.txt'),
            ],
            'move a file' => [
                $unlocked,
                $file,
                static fn(FilesystemAdapter $disk): mixed => $disk->move('file.txt', 'moved/file.txt'),
            ],
            'move a missing file' => [
                $unlocked,
                $nothing,
                static fn(FilesystemAdapter $disk): mixed => $disk->move('missing.txt', 'moved.txt'),
            ],
            'delete a file' => [
                $unlocked,
                $file,
                static fn(FilesystemAdapter $disk): mixed => $disk->delete('file.txt'),
            ],
            'delete a missing file' => [
                $unlocked,
                $nothing,
                static fn(FilesystemAdapter $disk): mixed => $disk->delete('missing.txt'),
            ],
            'delete a directory as a file' => [
                $unlocked,
                $tree,
                static fn(FilesystemAdapter $disk): mixed => $disk->delete('directory'),
            ],
            'delete a symlink' => [
                $unlocked,
                $links,
                static fn(FilesystemAdapter $disk): mixed => $disk->delete('link.txt'),
            ],
            'delete a dangling symlink' => [
                $unlocked,
                $links,
                static fn(FilesystemAdapter $disk): mixed => $disk->delete('dangling.txt'),
            ],
            'delete a symlinked directory' => [
                $unlocked,
                $links,
                static fn(FilesystemAdapter $disk): mixed => $disk->deleteDirectory('linked-directory'),
            ],
            'delete a directory' => [
                $unlocked,
                $tree,
                static fn(FilesystemAdapter $disk): mixed => $disk->deleteDirectory('directory'),
            ],
            'delete a missing directory' => [
                $unlocked,
                $nothing,
                static fn(FilesystemAdapter $disk): mixed => $disk->deleteDirectory('missing'),
            ],
            'delete a file as a directory' => [
                $unlocked,
                $file,
                static fn(FilesystemAdapter $disk): mixed => $disk->deleteDirectory('file.txt'),
            ],
            'what exists' => [
                $unlocked,
                $tree,
                static fn(FilesystemAdapter $disk): mixed => [
                    $disk->exists('file.txt'),
                    $disk->exists('directory'),
                    $disk->exists('missing'),
                    $disk->fileExists('directory'),
                    $disk->directoryExists('file.txt'),
                    $disk->directoryExists('directory/nested'),
                ],
            ],
            'size of a file' => [
                $unlocked,
                $file,
                static fn(FilesystemAdapter $disk): mixed => $disk->size('file.txt'),
            ],
            'size of a missing file' => [
                $unlocked,
                $nothing,
                static fn(FilesystemAdapter $disk): mixed => $disk->size('missing.txt'),
            ],
            'size of a directory' => [
                $unlocked,
                $tree,
                static fn(FilesystemAdapter $disk): mixed => $disk->size('directory'),
            ],
            'last modified' => [
                $unlocked,
                static function (string $root): void {
                    file_put_contents($root . '/file.txt', 'contents');
                    touch($root . '/file.txt', 1_700_000_000);
                },
                static fn(FilesystemAdapter $disk): mixed => $disk->lastModified('file.txt'),
            ],
            'last modified of a missing file' => [
                $unlocked,
                $nothing,
                static fn(FilesystemAdapter $disk): mixed => $disk->lastModified('missing.txt'),
            ],
            'visibility' => [
                $unlocked,
                $file,
                static fn(FilesystemAdapter $disk): mixed => [
                    $disk->getVisibility('file.txt'),
                ],
            ],
            'visibility of a missing file' => [
                $unlocked,
                $nothing,
                static fn(FilesystemAdapter $disk): mixed => $disk->getVisibility('missing.txt'),
            ],
            'checksum md5 by default' => [
                $unlocked,
                $file,
                static fn(FilesystemAdapter $disk): mixed => $disk->checksum('file.txt'),
            ],
            'checksum sha256' => [
                $unlocked,
                $file,
                static fn(FilesystemAdapter $disk): mixed => $disk->checksum('file.txt', ['checksum_algo' => 'sha256']),
            ],
            'checksum the feature does not know' => [
                $unlocked,
                $file,
                static fn(FilesystemAdapter $disk): mixed => $disk->checksum('file.txt', ['checksum_algo' => 'crc32b']),
            ],
            'checksum of a missing file' => [
                $unlocked,
                $nothing,
                static fn(FilesystemAdapter $disk): mixed => $disk->checksum('missing.txt'),
            ],
            'listing stays the parent\'s' => [
                $unlocked,
                $tree,
                static fn(FilesystemAdapter $disk): mixed => [
                    $disk->allFiles(),
                    $disk->allDirectories(),
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function disk(string $driver, array $config): FilesystemAdapter
    {
        $disk = Storage::build([
            'driver' => $driver,
            'root'   => $this->root,
            'throw'  => true,
            ...$config,
        ]);

        assert($disk instanceof FilesystemAdapter);

        return $disk;
    }
}
