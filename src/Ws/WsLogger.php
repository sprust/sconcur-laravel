<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws;

use DateTimeImmutable;

/**
 * One line per pool event, scoped by who produced it — the same shape the task pool
 * writes in.
 *
 * The master merges every worker's stdout into one journal, so a line has to say who
 * wrote it, and the scope is what says it: repeating the source inside the message would
 * print it twice. Written and flushed immediately: with stdout redirected to a file the
 * stream is block buffered, and the lines a shutting-down worker writes would never
 * arrive.
 */
class WsLogger
{
    public function __construct(protected string $stream = 'php://stdout')
    {
    }

    public function log(string $scope, string $message): void
    {
        // Silenced, and the guard below is why: an application error handler turns the
        // warning into an exception, which would escape a log line and take the caller
        // with it — including a connection teardown, which must finish.
        $handle = @fopen($this->stream, 'a');

        if ($handle === false) {
            return;
        }

        fwrite(
            $handle,
            sprintf(
                '%s [ws %s] %s%s',
                new DateTimeImmutable()->format('Y-m-d\TH:i:s.u'),
                $scope,
                $message,
                PHP_EOL,
            ),
        );

        fflush($handle);
        fclose($handle);
    }
}
