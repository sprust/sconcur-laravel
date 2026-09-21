<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Filesystem;

use Closure;
use Illuminate\Filesystem\Filesystem as IlluminateFilesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Laravel\Filesystem\Filesystem;

/**
 * The `files` binding on the Files feature answers exactly as the framework's own: every
 * case runs through Illuminate\Filesystem\Filesystem and through the package's subclass,
 * inside a coroutine where the feature is used and outside one where it is not.
 */
class FilesystemParityTest extends BaseFilesystemTestCase
{
    /**
     * @param Closure(string): void                        $arrange
     * @param Closure(IlluminateFilesystem, string): mixed $act
     */
    #[Test]
    #[DataProvider('cases')]
    public function itAnswersLikeTheFrameworksFilesystem(Closure $arrange, Closure $act): void
    {
        foreach ([true, false] as $inCoroutine) {
            $native = $this->outcome(
                arrange: $arrange,
                act: fn(): mixed => $act(new IlluminateFilesystem(), $this->root),
                inCoroutine: $inCoroutine,
            );

            $sconcur = $this->outcome(
                arrange: $arrange,
                act: fn(): mixed => $act(new Filesystem(), $this->root),
                inCoroutine: $inCoroutine,
            );

            self::assertSame($native, $sconcur, $inCoroutine ? 'in a coroutine' : 'outside a coroutine');
        }
    }

    /**
     * Hashing on the feature leaves the process's other coroutines running, and the
     * measurement tells: the same hash taken natively holds them for its whole length.
     */
    #[Test]
    public function aHashDoesNotHoldTheProcess(): void
    {
        $path = $this->root . '/large.bin';

        $this->largeFile($path);

        $nativeStallMs = $this->longestStallMs([
            static function () use ($path): void {
                // Suspended first, so the ticker is running by the time the hash starts.
                Sleeper::usleep(30_000);

                (new IlluminateFilesystem())->hash($path, 'sha256');
            },
        ]);

        $featureStallMs = $this->longestStallMs([
            static function () use ($path): void {
                Sleeper::usleep(30_000);

                (new Filesystem())->hash($path, 'sha256');
            },
        ]);

        self::assertGreaterThan(30.0, $nativeStallMs, 'the measurement does not see a native hash');
        self::assertLessThan($nativeStallMs / 3, $featureStallMs);
    }

    /**
     * @return array<string, array{Closure(string): void, Closure(IlluminateFilesystem, string): mixed}>
     */
    public static function cases(): array
    {
        $source = static function (string $root): void {
            file_put_contents($root . '/source.txt', 'contents');
        };

        $sourceAndTarget = static function (string $root): void {
            file_put_contents($root . '/source.txt', 'contents');
            file_put_contents($root . '/target.txt', 'old');
            chmod($root . '/target.txt', 0600);
        };

        $nothing = static function (string $root): void {
        };

        return [
            'copy to a new file' => [
                $source,
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->copy($root . '/source.txt', $root . '/copy.txt'),
            ],
            'copy over a file keeps its permissions' => [
                $sourceAndTarget,
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->copy($root . '/source.txt', $root . '/target.txt'),
            ],
            'copy onto itself' => [
                $source,
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->copy($root . '/source.txt', $root . '/source.txt'),
            ],
            'copy of a missing file' => [
                $nothing,
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->copy($root . '/missing.txt', $root . '/copy.txt'),
            ],
            'copy into a missing directory' => [
                $source,
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->copy($root . '/source.txt', $root . '/missing/copy.txt'),
            ],
            'move to a new file' => [
                $source,
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->move($root . '/source.txt', $root . '/moved.txt'),
            ],
            'move over a file' => [
                $sourceAndTarget,
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->move($root . '/source.txt', $root . '/target.txt'),
            ],
            'move of a missing file' => [
                $nothing,
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->move($root . '/missing.txt', $root . '/moved.txt'),
            ],
            'move of a directory' => [
                static function (string $root): void {
                    mkdir($root . '/directory');
                    file_put_contents($root . '/directory/file.txt', 'contents');
                },
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->move($root . '/directory', $root . '/renamed'),
            ],
            'hash md5 by default' => [
                $source,
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->hash($root . '/source.txt'),
            ],
            'hash sha256' => [
                $source,
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->hash($root . '/source.txt', 'sha256'),
            ],
            'hash in upper case' => [
                $source,
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->hash($root . '/source.txt', 'SHA1'),
            ],
            'hash the feature does not know' => [
                $source,
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->hash($root . '/source.txt', 'xxh128'),
            ],
            'hash of a missing file' => [
                $nothing,
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->hash($root . '/missing.txt', 'sha256'),
            ],
            'replace a new file' => [
                $nothing,
                static function (IlluminateFilesystem $files, string $root): mixed {
                    $files->replace($root . '/replaced.txt', 'contents');

                    return null;
                },
            ],
            'replace a file with a mode' => [
                $sourceAndTarget,
                static function (IlluminateFilesystem $files, string $root): mixed {
                    $files->replace($root . '/target.txt', 'new', 0640);

                    return null;
                },
            ],
            'replace through a symlink' => [
                static function (string $root): void {
                    file_put_contents($root . '/real.txt', 'old');
                    symlink($root . '/real.txt', $root . '/link.txt');
                },
                static function (IlluminateFilesystem $files, string $root): mixed {
                    $files->replace($root . '/link.txt', 'new');

                    return null;
                },
            ],
        ];
    }
}
