<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Ws\WsLogger;

/**
 * One line per pool event.
 *
 * The master merges every worker's stdout into one journal, so a line has to say who
 * wrote it — and it has to arrive: with stdout redirected to a file the stream is block
 * buffered, and a shutting-down worker's lines would otherwise never be written.
 */
class WsLoggerTest extends BaseTestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'ws-log-');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    #[Test]
    public function itWritesTheScopeAndTheMessage(): void
    {
        new WsLogger(stream: $this->path)->log('bus', 'subscribed as amq.gen-x');

        self::assertStringContainsString('[ws bus] subscribed as amq.gen-x', $this->contents());
    }

    #[Test]
    public function everyLineCarriesATimestamp(): void
    {
        new WsLogger(stream: $this->path)->log('conn', 'anything');

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6} \[ws conn]/', $this->contents());
    }

    #[Test]
    public function linesAccumulateRatherThanReplaceEachOther(): void
    {
        $logger = new WsLogger(stream: $this->path);

        $logger->log('bus', 'first');
        $logger->log('conn', 'second');

        self::assertCount(2, array_filter(explode(PHP_EOL, $this->contents())));
    }

    /** A log that cannot be opened must not take the worker down with it. */
    #[Test]
    public function anUnwritableStreamIsSwallowed(): void
    {
        new WsLogger(stream: '/proc/self/no/such/place')->log('bus', 'anything');

        self::assertFileDoesNotExist('/proc/self/no/such/place');
    }

    private function contents(): string
    {
        return (string) file_get_contents($this->path);
    }
}
