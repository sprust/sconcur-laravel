<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws\Support;

/**
 * The recording bus with the publisher's idle limit brought down to nothing, so every
 * publish finds the kept channel too old to use.
 *
 * The real limit is ten minutes, and a test that waited it out would be a ten minute test.
 * What is worth pinning is the rule rather than the number: a channel past the limit is
 * given up on the way in, and the publish that follows dials again.
 */
class ImpatientPublisherBus extends RecordingPublisherBus
{
    protected const float MAX_PUBLISHER_IDLE_SECONDS = 0.0;
}
