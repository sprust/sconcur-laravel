<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws;

use DateTimeImmutable;

/**
 * One line per pool event, in the same shape the task pool writes.
 *
 * The master merges every worker's stdout into one journal, so a line has to say who
 * wrote it. Written and flushed immediately: with stdout redirected to a file the stream
 * is block buffered, and the lines a shutting-down worker writes would never arrive.
 */
class WsLogger
{
    public function __construct(protected string $stream = 'php://stdout')
    {
    }

    public function log(string $message): void
    {
        $handle = fopen($this->stream, 'a');

        if ($handle === false) {
            return;
        }

        fwrite(
            $handle,
            sprintf('%s [ws] %s%s', new DateTimeImmutable()->format('Y-m-d\TH:i:s.u'), $message, PHP_EOL),
        );

        fflush($handle);
        fclose($handle);
    }
}
