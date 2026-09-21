<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Filesystem;

use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SConcur\Exceptions\CallbackExecutionException;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Laravel\Filesystem\Filesystem;
use SConcur\Laravel\Filesystem\SconcurLocalFilesystemAdapter;
use SConcur\Laravel\Support\CooperativeSleep;
use SConcur\WaitGroup;

/**
 * A coroutine being unwound — a sibling threw, WaitGroup::stop() ran — still runs its
 * `finally`, and the extension no longer resumes it: a call that suspended there would hang
 * for good. The package's cooperative calls have to run natively in that state, as they do
 * outside a coroutine, so the `finally` finishes and does its work.
 */
class CoroutineUnwindTest extends BaseFilesystemTestCase
{
    /**
     * @param Closure(Filesystem, FilesystemAdapter, string): void $cleanUp
     */
    #[Test]
    #[DataProvider('cases')]
    public function aFinallyOfAnUnwoundCoroutineFinishes(Closure $cleanUp, string $gone): void
    {
        file_put_contents($this->root . '/file.txt', 'contents');

        $filesystem = new Filesystem();

        $disk = Storage::build([
            'driver' => SconcurLocalFilesystemAdapter::DRIVER,
            'root'   => $this->root,
            'lock'   => 0,
        ]);

        assert($disk instanceof FilesystemAdapter);

        $log  = [];
        $root = $this->root;

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: static function () use ($cleanUp, $filesystem, $disk, $root, &$log): void {
                try {
                    Sleeper::sleep(5);
                } finally {
                    $log[] = 'started';

                    $cleanUp($filesystem, $disk, $root);

                    $log[] = 'finished';
                }
            },
            timeoutMs: self::COROUTINE_TIMEOUT_MS,
        );

        // The sibling suspends first, so its exception comes out of waitAll(), which then
        // unwinds the rest of the group — the coroutine above among it.
        try {
            $waitGroup->add(
                callback: static function (): void {
                    Sleeper::usleep(1_000);

                    throw new RuntimeException('a sibling fails');
                },
                timeoutMs: self::COROUTINE_TIMEOUT_MS,
            );

            $waitGroup->waitAll();
        } catch (CallbackExecutionException) {
        }

        clearstatcache();

        self::assertSame(['started', 'finished'], $log);
        self::assertFileDoesNotExist($this->root . '/' . $gone);
    }

    /**
     * @return array<string, array{Closure(Filesystem, FilesystemAdapter, string): void, string}>
     */
    public static function cases(): array
    {
        return [
            'disk delete' => [
                static function (Filesystem $filesystem, FilesystemAdapter $disk): void {
                    $disk->delete('file.txt');
                },
                'file.txt',
            ],
            'File::replace, then a native unlink' => [
                static function (Filesystem $filesystem, FilesystemAdapter $disk, string $root): void {
                    $filesystem->replace($root . '/file.txt', 'new');

                    unlink($root . '/file.txt');
                },
                'file.txt',
            ],
            'a cooperative pause, then a native unlink' => [
                static function (Filesystem $filesystem, FilesystemAdapter $disk, string $root): void {
                    CooperativeSleep::usleep(1_000);

                    unlink($root . '/file.txt');
                },
                'file.txt',
            ],
        ];
    }
}
