<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Filesystem;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Tests\Feature\MeasuresStallsTrait;
use SConcur\WaitGroup;
use SplFileInfo;
use Throwable;

/**
 * Parity between a native file implementation and its Files-feature counterpart: the same
 * scenario runs against each on the same freshly built tree, and what it answered, what it
 * threw and what it left on the disk have to be identical.
 *
 * Same tree, same paths, one after the other rather than side by side — the messages of
 * native failures carry the path, and two directories would make every one of them differ.
 */
abstract class BaseFilesystemTestCase extends BaseTestCase
{
    use MeasuresStallsTrait;

    protected const int COROUTINE_TIMEOUT_MS = 10_000;

    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/sconcur-files-' . bin2hex(random_bytes(6));

        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->wipe();

        @rmdir($this->root);

        parent::tearDown();
    }

    /**
     * Runs the callback as a coroutine and answers what it returned, or throws what it threw.
     *
     * @template TResult
     *
     * @param Closure(): TResult $callback
     *
     * @return TResult
     */
    protected function inCoroutine(Closure $callback): mixed
    {
        $result   = null;
        $finished = false;
        $caught   = null;

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: static function () use ($callback, &$result, &$finished, &$caught): void {
                try {
                    $result   = $callback();
                    $finished = true;
                } catch (Throwable $exception) {
                    $caught = $exception;
                }
            },
            timeoutMs: self::COROUTINE_TIMEOUT_MS,
        );

        $waitGroup->waitAll();

        if ($caught !== null) {
            throw $caught;
        }

        assert($finished);

        /** @var TResult $result */
        return $result;
    }

    /**
     * What one run of a scenario amounts to: its answer or its exception, and the tree it
     * left behind.
     *
     * @param Closure(string): void $arrange
     * @param Closure(): mixed      $act
     *
     * @return array<string, mixed>
     */
    protected function outcome(Closure $arrange, Closure $act, bool $inCoroutine): array
    {
        $this->wipe();

        $arrange($this->root);

        clearstatcache();

        try {
            $answer = ['result' => $inCoroutine ? $this->inCoroutine($act) : $act()];
        } catch (Throwable $exception) {
            $answer = [
                'exception' => $exception::class,
                'message'   => $exception->getMessage(),
            ];
        }

        return [
            ...$answer,
            'tree' => $this->tree(),
        ];
    }

    /**
     * Every entry under the root: its type, and for a file its contents and permission bits,
     * for a directory its permission bits, for a symlink its target.
     *
     * @return array<string, array<string, int|string>>
     */
    protected function tree(): array
    {
        clearstatcache();

        $tree = [];

        foreach ($this->entries() as $entry) {
            $relative = substr($entry->getPathname(), strlen($this->root) + 1);

            $tree[$relative] = match (true) {
                $entry->isLink() => [
                    'type'   => 'link',
                    'target' => (string) readlink($entry->getPathname()),
                ],
                $entry->isDir() => [
                    'type'        => 'dir',
                    'permissions' => fileperms($entry->getPathname()) & 07777,
                ],
                default => [
                    'type'        => 'file',
                    'contents'    => (string) file_get_contents($entry->getPathname()),
                    'permissions' => fileperms($entry->getPathname()) & 07777,
                ],
            };
        }

        ksort($tree);

        return $tree;
    }

    protected function wipe(): void
    {
        foreach (array_reverse(iterator_to_array($this->entries(), false)) as $entry) {
            $entry->isDir() && !$entry->isLink()
                ? rmdir($entry->getPathname())
                : unlink($entry->getPathname());
        }
    }

    /**
     * @return iterable<SplFileInfo>
     */
    protected function entries(): iterable
    {
        if (!is_dir($this->root)) {
            return [];
        }

        /** @var iterable<SplFileInfo> */
        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
    }

    /** A file big enough that hashing it natively holds the process for a measurable while. */
    protected function largeFile(string $path): void
    {
        $handle = fopen($path, 'wb');

        assert($handle !== false);

        $block = random_bytes(1_048_576);

        for ($mebibyte = 0; $mebibyte < 64; $mebibyte++) {
            fwrite($handle, $block);
        }

        fclose($handle);
    }
}
