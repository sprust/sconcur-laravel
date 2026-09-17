<?php

declare(strict_types=1);

namespace SConcur\Laravel\Redis\Exceptions;

use LogicException;

/**
 * A cluster was asked of the `sconcur` Redis client.
 *
 * The feature has no cluster on purpose rather than by omission: keys route by slot, the
 * server answers MOVED/ASK mid-command, a pipeline cannot span slots and pub/sub needs
 * sharded channels — a different connection model, not a switch. A cluster entry quietly
 * served by one of its nodes would look like it works until the first key of another slot.
 */
class RedisClusterNotSupportedException extends LogicException
{
}
