<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws\Support;

use Closure;
use SConcur\Exceptions\WsServer\WsServerConnectionClosedException;
use SConcur\Features\WsServer\Dto\Connection;

/**
 * A Connection with the network taken out: read() hands over a scripted frame, write()
 * records one.
 *
 * The base class is only a handle — its constructor stores identifiers and nothing else —
 * so a subclass that overrides the two methods that cross into the extension is a
 * complete stand-in for a client, and the handler under test cannot tell.
 */
class FakeConnection extends Connection
{
    /** @var list<string> */
    public array $written = [];

    /** Makes every write fail, the way a client that has already gone does. */
    public bool $failWrites = false;

    /**
     * Runs once when the scripted frames run out — that is, between the last thing the
     * client said and the teardown. It is where a test breaks something that the
     * teardown then has to survive.
     */
    public ?Closure $onDrained = null;

    /** @var list<string> */
    private array $inbound;

    /**
     * @param list<string> $inbound frames the client sends, in order
     */
    public function __construct(array $inbound = [], string $path = '/app/testkey', string $id = 'c1')
    {
        parent::__construct(
            id: $id,
            remoteAddr: '127.0.0.1:1000',
            localAddr: '127.0.0.1:28095',
            path: $path,
            subprotocol: '',
        );

        $this->inbound = $inbound;
    }

    public function read(): ?string
    {
        $message = array_shift($this->inbound);

        if ($message !== null || $this->onDrained === null) {
            return $message;
        }

        $onDrained = $this->onDrained;

        $this->onDrained = null;

        $onDrained();

        return null;
    }

    public function write(string $data, bool $binary = false): void
    {
        if ($this->failWrites) {
            throw new WsServerConnectionClosedException(message: 'Connection is closed.');
        }

        $this->written[] = $data;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /**
     * The frames written so far, decoded.
     *
     * @return list<array<string, mixed>>
     */
    public function frames(): array
    {
        return array_map(
            static fn(string $frame): array => (array) json_decode($frame, true),
            $this->written,
        );
    }

    /**
     * @return list<string>
     */
    public function events(): array
    {
        return array_map(static fn(array $frame): string => (string) ($frame['event'] ?? ''), $this->frames());
    }

    /** The channel of the first frame carrying this event. */
    public function channelOf(string $event): ?string
    {
        foreach ($this->frames() as $frame) {
            if (($frame['event'] ?? null) === $event) {
                return $frame['channel'] ?? null;
            }
        }

        return null;
    }

    /**
     * The decoded `data` of the first frame carrying this event.
     *
     * @return null|array<string, mixed>
     */
    public function dataOf(string $event): ?array
    {
        foreach ($this->frames() as $frame) {
            if (($frame['event'] ?? null) !== $event) {
                continue;
            }

            $data = $frame['data'] ?? '{}';

            return (array) json_decode(is_string($data) ? $data : '{}', true);
        }

        return null;
    }
}
