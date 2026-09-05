<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws\Support;

use RuntimeException;

/** What a cache store throws here when it is pretending to be unreachable. */
class StoreIsDownException extends RuntimeException
{
}
