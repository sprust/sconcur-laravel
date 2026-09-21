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
 * PHP answers file_exists(), is_file(), filesize() and the rest from the last stat it made,
 * and the feature changes the disk past PHP. So each change is made with the path it
 * touches in that cache, and PHP's own functions have to see the change straight after —
 * without the caller clearing anything.
 */
class StatCacheTest extends BaseFilesystemTestCase
{
    /**
     * @param Closure(Filesystem, FilesystemAdapter, string): mixed $act
     * @param list<string>                                          $paths relative to the root
     */
    #[Test]
    #[DataProvider('cases')]
    public function aChangeOnTheFeatureIsSeenAtOnce(Closure $act, array $paths): void
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

        $this->inCoroutine(function () use ($act, $paths, $filesystem, $disk): void {
            foreach ($paths as $path) {
                $this->statOf($this->root . '/' . $path);
            }

            $act($filesystem, $disk, $this->root);
        });

        $seen = [];

        foreach ($paths as $path) {
            $seen[$path] = $this->statOf($this->root . '/' . $path);
        }

        clearstatcache(true);

        $true = [];

        foreach ($paths as $path) {
            $true[$path] = $this->statOf($this->root . '/' . $path);
        }

        self::assertSame($true, $seen);
    }

    /**
     * @return array<string, array{Closure(Filesystem, FilesystemAdapter, string): mixed, list<string>}>
     */
    public static function cases(): array
    {
        return [
            'File::copy' => [
                static fn(Filesystem $filesystem, FilesystemAdapter $disk, string $root): mixed => $filesystem->copy($root . '/file.txt', $root . '/other.txt'),
                ['other.txt'],
            ],
            'File::move' => [
                static fn(Filesystem $filesystem, FilesystemAdapter $disk, string $root): mixed => $filesystem->move($root . '/file.txt', $root . '/other.txt'),
                ['other.txt', 'file.txt'],
            ],
            'File::replace' => [
                static function (Filesystem $filesystem, FilesystemAdapter $disk, string $root): mixed {
                    $filesystem->replace($root . '/file.txt', 'much longer contents');

                    return null;
                },
                ['file.txt'],
            ],
            'disk put' => [
                static fn(Filesystem $filesystem, FilesystemAdapter $disk): mixed => $disk->put('file.txt', 'much longer contents'),
                ['file.txt'],
            ],
            'disk writeStream' => [
                static function (Filesystem $filesystem, FilesystemAdapter $disk): mixed {
                    $stream = fopen('php://temp', 'w+b');

                    assert($stream !== false);

                    fwrite($stream, 'much longer contents');
                    rewind($stream);

                    return $disk->writeStream('file.txt', $stream);
                },
                ['file.txt'],
            ],
            'disk copy' => [
                static fn(Filesystem $filesystem, FilesystemAdapter $disk): mixed => $disk->copy('file.txt', 'other.txt'),
                ['other.txt'],
            ],
            'disk move' => [
                static fn(Filesystem $filesystem, FilesystemAdapter $disk): mixed => $disk->move('file.txt', 'other.txt'),
                ['other.txt', 'file.txt'],
            ],
            'disk delete' => [
                static fn(Filesystem $filesystem, FilesystemAdapter $disk): mixed => $disk->delete('file.txt'),
                ['file.txt'],
            ],
            'disk deleteDirectory' => [
                static fn(Filesystem $filesystem, FilesystemAdapter $disk): mixed => $disk->deleteDirectory('directory'),
                ['directory'],
            ],
        ];
    }

    /**
     * What PHP's stat family answers for the path, from its cache when it has one.
     *
     * @return array<string, bool|int>
     */
    protected function statOf(string $path): array
    {
        return [
            'exists'    => file_exists($path),
            'file'      => is_file($path),
            'directory' => is_dir($path),
            'size'      => is_file($path) ? (int) filesize($path) : -1,
        ];
    }
}
