<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tasks;

use DateTimeImmutable;

/**
 * One line per pool event, scoped by who produced it.
 *
 * The pool replaces several supervisor programs that each had their own log file, so the
 * scope is what keeps the merged stream readable. Written and flushed immediately: with
 * stdout redirected to a file the stream is block buffered, and shutdown lines would
 * otherwise never reach the log of a process that is shutting down.
 */
class TaskPoolLogger
{
    public function __construct(protected string $stream = 'php://stdout')
    {
    }

    public function log(string $scope, string $message): void
    {
        $handle = fopen($this->stream, 'a');

        if ($handle === false) {
            return;
        }

        fwrite(
            $handle,
            sprintf('%s [%s] %s%s', new DateTimeImmutable()->format('Y-m-d\TH:i:s.u'), $scope, $message, PHP_EOL),
        );

        fflush($handle);
        fclose($handle);
    }
}
