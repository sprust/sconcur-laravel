<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Filesystem;

use Closure;
use Illuminate\Filesystem\Filesystem as IlluminateFilesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Filesystem\Filesystem;
use SConcur\Laravel\Filesystem\SconcurLocalFilesystemAdapter;

/**
 * The parity tests say the package answers like the native code; they cannot say the
 * feature did the work, because a call that silently fell back would answer the same.
 * This does: inside a coroutine the package's call makes none of the native file
 * operations that do the work — open, rename, unlink, mkdir, rmdir, opendir — that the
 * native call makes on the same tree.
 *
 * What it does not forbid: stat, which the package makes itself for the checks it runs
 * before the feature, and chmod/touch, which it makes to apply a visibility as the parent
 * does. What the spy cannot see at all: realpath(), tempnam(), link(), symlink() and
 * readlink(), which do not go through a stream wrapper.
 *
 * The native call is run first as the control — it has to show the operations, or the
 * spy is not seeing anything and an empty list would prove nothing.
 */
class FeaturePathTest extends BaseFilesystemTestCase
{
    /** The operations that mean the file work itself was done natively. */
    protected const array WORK_OPERATIONS = [
        'open',
        'rename',
        'unlink',
        'mkdir',
        'rmdir',
        'opendir',
    ];

    /**
     * @param Closure(string): void         $arrange
     * @param Closure(mixed, string): mixed $act
     * @param list<string>                  $forbidden operations beyond WORK_OPERATIONS
     */
    #[Test]
    #[DataProvider('cases')]
    public function theWorkIsDoneOnTheFeature(
        string $subject,
        Closure $arrange,
        Closure $act,
        array $forbidden = [],
    ): void {
        $forbidden = [...self::WORK_OPERATIONS, ...$forbidden];

        $nativeCalls = $this->callsOf(
            subject: $this->subject(kind: $subject, native: true),
            arrange: $arrange,
            act: $act,
        );

        $featureCalls = $this->callsOf(
            subject: $this->subject(kind: $subject, native: false),
            arrange: $arrange,
            act: $act,
        );

        self::assertNotSame(
            [],
            $this->only(
                calls: $nativeCalls,
                operations: $forbidden,
            ),
            'the spy saw no native work to compare against',
        );

        self::assertSame(
            [],
            $this->only(
                calls: $featureCalls,
                operations: $forbidden,
            ),
        );
    }

    /**
     * @return array<string, array{
     *     0: string,
     *     1: Closure(string): void,
     *     2: Closure(mixed, string): mixed,
     *     3?: list<string>,
     * }>
     */
    public static function cases(): array
    {
        $file = static function (string $root): void {
            mkdir($root . '/directory');
            file_put_contents($root . '/directory/file.txt', 'contents');
            file_put_contents($root . '/file.txt', 'contents');
            file_put_contents($root . '/other.txt', 'other');
        };

        return [
            'File::copy' => [
                'files',
                $file,
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->copy($root . '/file.txt', $root . '/copy.txt'),
            ],
            'File::move' => [
                'files',
                $file,
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->move($root . '/file.txt', $root . '/moved.txt'),
            ],
            'File::copy onto an existing file' => [
                'files',
                $file,
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->copy($root . '/file.txt', $root . '/other.txt'),
            ],
            'File::move onto an existing file' => [
                'files',
                $file,
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->move($root . '/file.txt', $root . '/other.txt'),
            ],
            'File::hash' => [
                'files',
                $file,
                static fn(IlluminateFilesystem $files, string $root): mixed => $files->hash($root . '/file.txt', 'sha256'),
            ],
            'File::replace' => [
                'files',
                $file,
                static function (IlluminateFilesystem $files, string $root): mixed {
                    $files->replace($root . '/file.txt', 'new');

                    return null;
                },
            ],
            'disk put' => [
                'disk',
                $file,
                static fn(FilesystemAdapter $disk): mixed => $disk->put('new.txt', 'contents'),
            ],
            'disk writeStream' => [
                'disk',
                $file,
                static function (FilesystemAdapter $disk): mixed {
                    $stream = fopen('php://temp', 'w+b');

                    assert($stream !== false);

                    fwrite($stream, 'contents');
                    rewind($stream);

                    return $disk->writeStream('streamed.txt', $stream);
                },
            ],
            'disk get' => [
                'disk',
                $file,
                static fn(FilesystemAdapter $disk): mixed => $disk->get('file.txt'),
            ],
            'disk copy' => [
                'disk',
                $file,
                static fn(FilesystemAdapter $disk): mixed => $disk->copy('file.txt', 'copy.txt'),
            ],
            'disk move' => [
                'disk',
                $file,
                static fn(FilesystemAdapter $disk): mixed => $disk->move('file.txt', 'moved.txt'),
            ],
            'disk copy onto an existing file' => [
                'disk',
                $file,
                static fn(FilesystemAdapter $disk): mixed => $disk->copy('file.txt', 'other.txt'),
            ],
            'disk move onto an existing file' => [
                'disk',
                $file,
                static fn(FilesystemAdapter $disk): mixed => $disk->move('file.txt', 'other.txt'),
            ],
            'disk delete' => [
                'disk',
                $file,
                static fn(FilesystemAdapter $disk): mixed => $disk->delete('file.txt'),
            ],
            'disk deleteDirectory' => [
                'disk',
                $file,
                static fn(FilesystemAdapter $disk): mixed => $disk->deleteDirectory('directory'),
            ],
            'disk checksum' => [
                'disk',
                $file,
                static fn(FilesystemAdapter $disk): mixed => $disk->checksum('file.txt'),
            ],
            'disk metadata' => [
                'disk',
                $file,
                static fn(FilesystemAdapter $disk): mixed => [
                    $disk->fileExists('file.txt'),
                    $disk->directoryExists('directory'),
                    $disk->size('file.txt'),
                    $disk->lastModified('file.txt'),
                    $disk->getVisibility('file.txt'),
                ],
                ['stat'],
            ],
        ];
    }

    /**
     * @param Closure(string): void         $arrange
     * @param Closure(mixed, string): mixed $act
     *
     * @return list<string>
     */
    protected function callsOf(mixed $subject, Closure $arrange, Closure $act): array
    {
        $this->wipe();

        $arrange($this->root);

        return NativeFileCallSpy::watch(
            directory: $this->root,
            callback: fn(): mixed => $this->inCoroutine(fn(): mixed => $act($subject, $this->root)),
        );
    }

    protected function subject(string $kind, bool $native): mixed
    {
        if ($kind === 'disk') {
            $disk = Storage::build([
                'driver' => $native ? 'local' : SconcurLocalFilesystemAdapter::DRIVER,
                'root'   => $this->root,
                'lock'   => 0,
                'throw'  => true,
            ]);

            assert($disk instanceof FilesystemAdapter);

            return $disk;
        }

        return $native ? new IlluminateFilesystem() : new Filesystem();
    }

    /**
     * @param list<string> $calls
     * @param list<string> $operations
     *
     * @return list<string>
     */
    protected function only(array $calls, array $operations): array
    {
        return array_values(array_filter(
            $calls,
            static fn(string $call): bool => in_array(strtok($call, ' '), $operations, true),
        ));
    }
}
