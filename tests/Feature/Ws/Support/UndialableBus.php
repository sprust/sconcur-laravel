<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws\Support;

use SConcur\Exceptions\Amqp\ConnectionException;
use SConcur\Features\Amqp\Channel;

/**
 * The recording bus with its dialling turned off on demand, so a test can put a broker out
 * of reach between two publishes without taking the broker away from the suite.
 *
 * What it is for is the far side of the retry: the second attempt has no more attempts
 * behind it, and what it fails with has to reach the caller rather than be swallowed the
 * way the first one is.
 */
class UndialableBus extends RecordingPublisherBus
{
    /** Whether a dial from here on fails the way an unreachable broker fails. */
    public bool $dialsRefused = false;

    protected function openPublisher(): Channel
    {
        if ($this->dialsRefused) {
            throw new ConnectionException(message: 'No connection available.');
        }

        return parent::openPublisher();
    }
}
