<?php

declare(strict_types=1);

namespace SConcur\Laravel\Http;

use ArrayIterator;
use Closure;
use Fiber;
use Iterator;
use IteratorIterator;
use Psr\Http\Message\StreamInterface;
use SConcur\Exceptions\FlowStoppedException;
use RuntimeException;
use SConcur\Laravel\Support\Coroutine;
use SConcur\State;
use Stringable;
use Throwable;
use UnexpectedValueException;

/**
 * The body of a StreamedResponse built from chunks (see StreamedChunks), as a lazy PSR-7
 * stream of unknown size the extension's HTTP server drains read() by read().
 *
 * Every read() takes the next chunk, so every chunk the iterable yields is one chunk on the
 * wire, whether or not anything was waited on in between. A generator runs on the stack of
 * whoever advances it, which here is the request coroutine: what it waits on suspends that
 * coroutine the ordinary way, with its context, its deadline and preemption. No output
 * buffer is involved.
 *
 * $onFinished runs once, when the iterable is exhausted or has thrown — before read()
 * throws it on — or, for a body nobody reads to the end, when it is destroyed. What the
 * iterable throws is reported through $reportException first.
 */
class IterableResponseBody implements StreamInterface
{
    private const int READ_ALL_CHUNK_BYTES = 65_536;

    /** @var null|Iterator<mixed, mixed> */
    private ?Iterator $chunks = null;

    /** Taken from the iterable and not yet returned by read(). */
    private string $buffer = '';

    private bool $finished = false;

    private bool $onFinishedCalled = false;

    /**
     * @param Closure(): iterable<mixed> $chunksFactory
     * @param Closure(): void            $onFinished
     * @param Closure(Throwable): void   $reportException receives what the iterable throws, and
     *                                                    what $onFinished throws when nobody is
     *                                                    left to throw it to
     */
    public function __construct(
        private readonly Closure $chunksFactory,
        private readonly Closure $onFinished,
        private readonly Closure $reportException,
    ) {
    }

    public function read(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        while ($this->buffer === '' && !$this->finished) {
            $this->pull();
        }

        $chunk = substr($this->buffer, 0, $length);

        $this->buffer = substr($this->buffer, strlen($chunk));

        return $chunk;
    }

    public function eof(): bool
    {
        return $this->buffer === '' && $this->finished;
    }

    public function getContents(): string
    {
        $contents = '';

        while (($chunk = $this->read(self::READ_ALL_CHUNK_BYTES)) !== '') {
            $contents .= $chunk;
        }

        return $contents;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function close(): void
    {
        $this->abandon();
    }

    public function detach()
    {
        $this->abandon();

        return null;
    }

    public function tell(): int
    {
        throw new RuntimeException('A streamed response body has no position.');
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('A streamed response body is not seekable.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('A streamed response body is not seekable.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('A streamed response body is not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }

    /**
     * Takes the next chunk, or finishes the body when there is none.
     */
    private function pull(): void
    {
        try {
            if ($this->chunks === null) {
                $this->chunks = self::iterator(($this->chunksFactory)());

                $this->chunks->rewind();
            } else {
                $this->chunks->next();
            }

            if (!$this->chunks->valid()) {
                $this->finished = true;
                $this->chunks   = null;

                $this->callOnFinished();

                return;
            }

            $this->buffer .= self::chunkString($this->chunks->current());
        } catch (Throwable $exception) {
            if ($this->finished) {
                // $onFinished itself failed: the body is complete, the failure is its own.
                throw $exception;
            }

            $this->finished = true;
            $this->chunks   = null;

            // The head is on the wire, so the server can only end the stream, and it tells
            // nobody: the worker's server has no onError. A stop — a deadline, a shutdown —
            // is not a failure and is not reported.
            if (!$exception instanceof FlowStoppedException) {
                ($this->reportException)($exception);
            }

            try {
                $this->callOnFinished();
            } catch (Throwable $onFinishedException) {
                ($this->reportException)($onFinishedException);
            }

            throw $exception;
        }
    }

    private function abandon(): void
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;
        $this->buffer   = '';

        try {
            $this->chunks = null;

            $this->callOnFinished();
        } catch (Throwable $exception) {
            ($this->reportException)($exception);
        }
    }

    /**
     * Runs $onFinished once, and not in a coroutine a shutdown has let go: it would hang in
     * the first wait of it, and the process is going anyway.
     */
    private function callOnFinished(): void
    {
        if ($this->onFinishedCalled) {
            return;
        }

        $this->onFinishedCalled = true;

        $fiber = Fiber::getCurrent();

        if ($fiber !== null
            && extension_loaded('sconcur')
            && State::isAsyncFiber(spl_object_id($fiber))
            && !Coroutine::isActive()
        ) {
            return;
        }

        ($this->onFinished)();
    }

    /**
     * @param iterable<mixed> $chunks
     *
     * @return Iterator<mixed, mixed>
     */
    private static function iterator(iterable $chunks): Iterator
    {
        if ($chunks instanceof Iterator) {
            return $chunks;
        }

        if (is_array($chunks)) {
            return new ArrayIterator($chunks);
        }

        return new IteratorIterator($chunks);
    }

    /**
     * What `echo $chunk` would print, which is what the same response prints outside this
     * server; a value echo would not print is refused.
     */
    private static function chunkString(mixed $chunk): string
    {
        if (is_string($chunk)) {
            return $chunk;
        }

        if ($chunk === null || is_scalar($chunk) || $chunk instanceof Stringable) {
            return (string) $chunk;
        }

        throw new UnexpectedValueException(
            sprintf('A streamed response chunk must be a string, got %s.', get_debug_type($chunk)),
        );
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    /**
     * A body the server stopped reading — the client went away, the handler ran past its
     * deadline — still has an iterable mid-way and a request to terminate. Dropping a
     * generator runs its finally blocks.
     */
    public function __destruct()
    {
        $this->abandon();
    }
}
