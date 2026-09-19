<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tasks\Events;

use SConcur\Laravel\Tasks\TickResultEnum;
use Throwable;

/**
 * A task's pass is over, whatever ended it: the tick returning, the tick throwing, or the
 * scheduler unwinding the coroutine on a stop or a blown deadline.
 *
 * `$exception` is null when the tick returned on its own; otherwise it is what it threw,
 * and `$result` is TickResultEnum::Failed.
 */
readonly class TaskTickFinished
{
    public function __construct(
        public string $name,
        public TickResultEnum $result,
        public ?Throwable $exception,
    ) {
    }
}
