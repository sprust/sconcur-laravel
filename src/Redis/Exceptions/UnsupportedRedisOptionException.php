<?php

declare(strict_types=1);

namespace SConcur\Laravel\Redis\Exceptions;

use LogicException;

/**
 * A connection entry or `redis.options` carries a key the feature does not read.
 *
 * Refused rather than ignored, the way the feature refuses an unknown DSN parameter: a
 * setting read by nothing means the connection does not behave the way the configuration
 * says it does, and that surfaces much later — as keys without the prefix the application
 * expects, or as a retry that never happens.
 */
class UnsupportedRedisOptionException extends LogicException
{
}
