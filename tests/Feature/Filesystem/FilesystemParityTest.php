<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Filesystem;

use Closure;
use Illuminate\Filesystem\Filesystem as IlluminateFilesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Laravel\Filesystem\Filesystem;
use SplFileInfo;

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

                (new IlluminateFilesystem())->hash($path, 'md5');
            },
        ]);

        $featureStallMs = $this->longestStallMs([
            static function () use ($path): void {
                Sleeper::usleep(30_000);

                (new Filesystem())->hash($path, 'md5');
            },
        ]);

        // md5 rather than sha256, whose hardware instructions leave too short a native stall
        // to tell apart from a tick. Relative rather than absolute: a loaded machine
        // stretches both, a fast one shortens both.
        self::assertGreaterThan(30.0, $nativeStallMs, 'the measurement does not see a native hash');
        self::assertLessThan($nativeStallMs / 2, $featureStallMs);
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
            'copy onto itself through a symlink' => [
                static function (string $root): void {
                    file_put_contents($root . '/source.txt', 'contents');
                    symlink($root . '/source.txt', $root . '/link.txt');
                },
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->copy($root . '/link.txt', $root . '/source.txt'),
            ],
            'copy onto itself through a hard link' => [
                static function (string $root): void {
                    file_put_contents($root . '/source.txt', 'contents');
                    link($root . '/source.txt', $root . '/hard.txt');
                },
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->copy($root . '/source.txt', $root . '/hard.txt'),
            ],
            'copy with a Stringable path' => [
                $source,
                // @phpstan-ignore argument.type (the parent's PHPDoc says string; PHP takes this too)
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->copy(new SplFileInfo($root . '/source.txt'), $root . '/copy.txt'),
            ],
            'copy a directory onto an existing file' => [
                static function (string $root): void {
                    mkdir($root . '/directory');
                    file_put_contents($root . '/target.txt', 'keep');
                },
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->copy($root . '/directory', $root . '/target.txt'),
            ],
            'copy onto a directory' => [
                static function (string $root): void {
                    file_put_contents($root . '/source.txt', 'contents');
                    mkdir($root . '/directory');
                },
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->copy($root . '/source.txt', $root . '/directory'),
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
            'move onto itself through a hard link' => [
                static function (string $root): void {
                    file_put_contents($root . '/source.txt', 'contents');
                    link($root . '/source.txt', $root . '/hard.txt');
                },
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->move($root . '/source.txt', $root . '/hard.txt'),
            ],
            'move with a Stringable path' => [
                $source,
                // @phpstan-ignore argument.type (the parent's PHPDoc says string; PHP takes this too)
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->move(new SplFileInfo($root . '/source.txt'), $root . '/moved.txt'),
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
            'hash of a directory' => [
                static function (string $root): void {
                    mkdir($root . '/directory');
                },
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->hash($root . '/directory', 'sha256'),
            ],
            'hash of a missing file' => [
                $nothing,
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->hash($root . '/missing.txt', 'sha256'),
            ],
            'hash with a Stringable path' => [
                $source,
                // @phpstan-ignore argument.type (the parent's PHPDoc says string; PHP takes this too)
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->hash(new SplFileInfo($root . '/source.txt'), 'sha256'),
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
            'replace with a setuid mode' => [
                $nothing,
                static function (IlluminateFilesystem $files, string $root): mixed {
                    $files->replace($root . '/replaced.txt', 'contents', 04755);

                    return null;
                },
            ],
            'replace with a mode from fileperms()' => [
                $nothing,
                static function (IlluminateFilesystem $files, string $root): mixed {
                    $files->replace($root . '/replaced.txt', 'contents', 0100640);

                    return null;
                },
            ],
            'replace with a mode as a string' => [
                $nothing,
                static function (IlluminateFilesystem $files, string $root): mixed {
                    // A string is read as a decimal number: '420' is 0644.
                    // @phpstan-ignore argument.type (the parent's PHPDoc says int; PHP takes this too)
                    $files->replace($root . '/replaced.txt', 'contents', '420');

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
            'replace with a resource' => [
                $nothing,
                static function (IlluminateFilesystem $files, string $root): mixed {
                    $stream = fopen('php://memory', 'w+b');

                    assert($stream !== false);

                    fwrite($stream, 'from a stream');
                    rewind($stream);

                    // @phpstan-ignore argument.type (the parent's PHPDoc says string; PHP takes this too)
                    $files->replace($root . '/replaced.txt', $stream);

                    return null;
                },
            ],
            'replace with an array' => [
                $nothing,
                static function (IlluminateFilesystem $files, string $root): mixed {
                    // @phpstan-ignore argument.type (the parent's PHPDoc says string; PHP takes this too)
                    $files->replace($root . '/replaced.txt', ['x', 'y']);

                    return null;
                },
            ],
        ];
    }
}
