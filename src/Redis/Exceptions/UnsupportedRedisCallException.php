<?php

declare(strict_types=1);

namespace SConcur\Laravel\Redis\Exceptions;

use LogicException;

/**
 * A call on the `sconcur` connection the feature deliberately has no path for — one that
 * would change the state of a connection other coroutines share (`MULTI` issued on its
 * own, `WATCH`, `SELECT`), or a subscription asked for as a plain command.
 *
 * The message names the replacement where there is one.
 */
class UnsupportedRedisCallException extends LogicException
{
}
