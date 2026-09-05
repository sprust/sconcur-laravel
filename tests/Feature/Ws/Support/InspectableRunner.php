<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws\Support;

use SConcur\Features\WsServer\WsServer;
use SConcur\Laravel\Ws\WsLogger;
use SConcur\Laravel\Ws\WsServerRunner;

/**
 * The runner with everything but serve() reachable.
 *
 * run() binds a listener and then serves until the process is signalled, so it is not
 * something a test can call. What it does before that — turning the collected flags into
 * a server and saying so when the handler deadline would drop every client — is, and
 * both have been wrong before.
 */
readonly class InspectableRunner extends WsServerRunner
{
    public function build(WsLogger $logger): WsServer
    {
        return $this->makeServer($logger);
    }

    public function warn(WsLogger $logger): void
    {
        $this->warnAboutHandlerTimeout($logger);
    }
}
