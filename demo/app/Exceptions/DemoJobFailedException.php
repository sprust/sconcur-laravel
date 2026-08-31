<?php

declare(strict_types=1);

namespace Demo\App\Exceptions;

use RuntimeException;

/**
 * Thrown by DemoJob when the payload asks for it, so the failed_jobs path is reachable
 * from the demo page. A class of its own rather than a bare RuntimeException, which is
 * what the library it demonstrates does with every failure it has a name for.
 */
class DemoJobFailedException extends RuntimeException
{
}
