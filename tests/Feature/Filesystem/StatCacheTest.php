<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Filesystem;

use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Filesystem\Filesystem;
use SConcur\Laravel\Filesystem\SconcurLocalFilesystemAdapter;

/**
 * PHP answers its stat family from the last stat() and lstat() it made, and resolves paths
 * through a realpath cache; the feature changes the disk past all three. Each change here is
 * made with the path it touches primed in those caches, and PHP has to see the change
 * straight after — without the caller clearing anything.
 *
 * What goes stale in practice is the realpath cache: it is kept per path for
 * realpath_cache_ttl, while the single stat and lstat entries are replaced by whatever
 * stats next — the suspension on the feature call itself does. So a removed file is where a
 * missing clear shows (a delete, a deleteDirectory), and the other cases guard the rest.
 */
class StatCacheTest extends BaseFilesystemTestCase
{
    /**
     * @param Closure(Filesystem, FilesystemAdapter, string): mixed $act
     */
    #[Test]
    #[DataProvider('cases')]
    public function aChangeOnTheFeatureIsSeenAtOnce(Closure $act, string $path): void
    {
        mkdir($this->root . '/directory');
        file_put_contents($this->root . '/directory/file.txt', 'contents');
        file_put_contents($this->root . '/file.txt', 'contents');
        file_put_contents($this->root . '/other.txt', 'other');

        $filesystem = new Filesystem();

        $disk = Storage::build([
            'driver' => SconcurLocalFilesystemAdapter::DRIVER,
            'root'   => $this->root,
            'lock'   => 0,
        ]);

        assert($disk instanceof FilesystemAdapter);

        $location = $this->root . '/' . $path;

        // Read back inside the coroutine, straight after the change: anything that stats
        // another path in between — the scheduler taking over, an autoload — would evict
        // the stale entry this looks for.
        $seen = $this->inCoroutine(function () use ($act, $location, $filesystem, $disk): array {
            $this->cachedStateOf($location);

            $act($filesystem, $disk, $this->root);

            return $this->cachedStateOf($location);
        });

        clearstatcache(true);

        self::assertSame($this->cachedStateOf($location), $seen);
    }

    /**
     * @return array<string, array{Closure(Filesystem, FilesystemAdapter, string): mixed, string}>
     */
    public static function cases(): array
    {
        return [
            'File::copy' => [
                static fn(Filesystem $filesystem, FilesystemAdapter $disk, string $root): mixed => $filesystem->copy($root . '/file.txt', $root . '/other.txt'),
                'other.txt',
            ],
            'File::replace' => [
                static function (Filesystem $filesystem, FilesystemAdapter $disk, string $root): mixed {
                    $filesystem->replace($root . '/file.txt', 'much longer contents');

                    return null;
                },
                'file.txt',
            ],
            'disk put' => [
                static fn(Filesystem $filesystem, FilesystemAdapter $disk): mixed => $disk->put('file.txt', 'much longer contents'),
                'file.txt',
            ],
            'disk writeStream' => [
                static function (Filesystem $filesystem, FilesystemAdapter $disk): mixed {
                    $stream = fopen('php://temp', 'w+b');

                    assert($stream !== false);

                    fwrite($stream, 'much longer contents');
                    rewind($stream);

                    return $disk->writeStream('file.txt', $stream);
                },
                'file.txt',
            ],
            'disk copy' => [
                static fn(Filesystem $filesystem, FilesystemAdapter $disk): mixed => $disk->copy('file.txt', 'other.txt'),
                'other.txt',
            ],
            'disk delete' => [
                static fn(Filesystem $filesystem, FilesystemAdapter $disk): mixed => $disk->delete('file.txt'),
                'file.txt',
            ],
            'disk deleteDirectory' => [
                static fn(Filesystem $filesystem, FilesystemAdapter $disk): mixed => $disk->deleteDirectory('directory'),
                'directory',
            ],
        ];
    }

    /**
     * What PHP answers for the path from its stat, lstat and realpath caches, when it has
     * them.
     *
     * @return array<string, false|int|string>
     */
    protected function cachedStateOf(string $path): array
    {
        $lstat = @lstat($path);
        $stat  = @stat($path);

        return [
            'lstatSize'  => $lstat === false ? -1 : $lstat['size'],
            'lstatInode' => $lstat === false ? -1 : $lstat['ino'],
            'statSize'   => $stat === false ? -1 : $stat['size'],
            'statInode'  => $stat === false ? -1 : $stat['ino'],
            'realpath'   => realpath($path),
        ];
    }
}
