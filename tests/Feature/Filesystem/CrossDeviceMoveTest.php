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
 * A move across filesystems stays native: rename() copies through the target path — into
 * the file a symlink there points at, with the source's mode — and the feature would
 * replace the target instead. Each case runs natively and through the package, and has
 * to leave the same files behind and go through rename().
 *
 * Needs a second filesystem beside the temporary directory; /dev/shm is one in the php
 * container. The disk reaches it through a symlinked directory under its root.
 */
class CrossDeviceMoveTest extends BaseFilesystemTestCase
{
    protected const string OTHER_DEVICE_DIRECTORY = '/dev/shm';

    protected string $otherDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $rootStat  = stat($this->root);
        $otherStat = @stat(self::OTHER_DEVICE_DIRECTORY);

        assert($rootStat !== false);

        if (
            $otherStat === false
            || !is_writable(self::OTHER_DEVICE_DIRECTORY)
            || $rootStat['dev'] === $otherStat['dev']
        ) {
            self::markTestSkipped('no second filesystem beside ' . sys_get_temp_dir());
        }

        $this->otherDirectory = self::OTHER_DEVICE_DIRECTORY . '/sconcur-move-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->wipeOtherDirectory();

        parent::tearDown();
    }

    #[Test]
    #[DataProvider('cases')]
    public function aMoveAcrossFilesystemsEndsAsNatively(string $subject, string $destination): void
    {
        $native  = $this->moveWith(subject: $subject, destination: $destination, native: true);
        $sconcur = $this->moveWith(subject: $subject, destination: $destination, native: false);

        self::assertContains('rename source.txt', $native['calls']);
        self::assertSame($native, $sconcur);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $cases = [];

        foreach (['files', 'disk'] as $subject) {
            foreach (['missing', 'file', 'symlink'] as $destination) {
                $cases["$subject onto a $destination destination"] = [
                    $subject,
                    $destination,
                ];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, mixed>
     */
    protected function moveWith(string $subject, string $destination, bool $native): array
    {
        $this->wipe();
        $this->wipeOtherDirectory();

        mkdir($this->otherDirectory);

        file_put_contents($this->root . '/source.txt', 'new');
        chmod($this->root . '/source.txt', 0640);

        $target = $this->otherDirectory . '/target.txt';

        if ($destination === 'file') {
            file_put_contents($target, 'old');
            chmod($target, 0604);
        }

        if ($destination === 'symlink') {
            file_put_contents($this->otherDirectory . '/pointee.txt', 'old');
            chmod($this->otherDirectory . '/pointee.txt', 0604);
            symlink($this->otherDirectory . '/pointee.txt', $target);
        }

        $move = $this->move(subject: $subject, native: $native);

        $calls = NativeFileCallSpy::watch(
            directory: $this->root,
            callback: fn(): mixed => $this->inCoroutine($move),
        );

        return [
            'calls'  => array_values(array_filter(
                $calls,
                static fn(string $call): bool => str_starts_with($call, 'rename '),
            )),
            'source' => file_exists($this->root . '/source.txt'),
            'other'  => $this->otherTree(),
        ];
    }

    /**
     * @return Closure(): mixed
     */
    protected function move(string $subject, bool $native): Closure
    {
        if ($subject === 'files') {
            $filesystem = $native ? new IlluminateFilesystem() : new Filesystem();

            return fn(): mixed => $filesystem->move($this->root . '/source.txt', $this->otherDirectory . '/target.txt');
        }

        symlink($this->otherDirectory, $this->root . '/other');

        $disk = Storage::build([
            'driver' => $native ? 'local' : SconcurLocalFilesystemAdapter::DRIVER,
            'root'   => $this->root,
            'throw'  => true,
        ]);

        assert($disk instanceof FilesystemAdapter);

        return static fn(): mixed => $disk->move('source.txt', 'other/target.txt');
    }

    /**
     * @return array<string, array<string, int|string>>
     */
    protected function otherTree(): array
    {
        clearstatcache();

        $tree = [];

        foreach ((array) scandir($this->otherDirectory) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $this->otherDirectory . '/' . $name;

            $tree[(string) $name] = is_link($path)
                ? [
                    'type'   => 'link',
                    'target' => (string) readlink($path),
                ]
                : [
                    'type'        => 'file',
                    'contents'    => (string) file_get_contents($path),
                    'permissions' => fileperms($path) & 07777,
                ];
        }

        ksort($tree);

        return $tree;
    }

    protected function wipeOtherDirectory(): void
    {
        if (!is_dir($this->otherDirectory)) {
            return;
        }

        foreach ((array) scandir($this->otherDirectory) as $name) {
            if ($name !== '.' && $name !== '..') {
                unlink($this->otherDirectory . '/' . $name);
            }
        }

        rmdir($this->otherDirectory);
    }
}
