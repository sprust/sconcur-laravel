<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws\Support;

use SConcur\Exceptions\Amqp\AmqpException;
use SConcur\Laravel\Ws\Bus\AmqpBroadcastBus;

/**
 * The AMQP bus with its idle-versus-failure decision exposed.
 *
 * The decision itself is not public, and should not be — but it is what tells a quiet
 * queue from a broken one, and getting it wrong is invisible until a broker goes away in
 * production. A subclass is the cheapest way to hold it to its wording.
 */
class ClassifyingBus extends AmqpBroadcastBus
{
    public function classify(AmqpException $exception): bool
    {
        return $this->isIdleTimeout($exception);
    }
}
