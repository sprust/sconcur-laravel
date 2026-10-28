<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws\Support;

use SConcur\Laravel\Console\WsStartCommand;

/**
 * The start command with everything but the serving reachable.
 *
 * handle() ends in a server that binds a listener and stays there, so what is held here
 * is what happens before that: the flags handed to the server.
 */
class InspectableStartCommand extends WsStartCommand
{
    /** @var array<string, string> */
    public array $options = [];

    /**
     * @return list<string>
     */
    public function args(): array
    {
        return $this->serverArgs();
    }

    /**
     * @param null|string $key
     *
     * @return null|array<array-key, mixed>|bool|string
     */
    public function option($key = null): mixed
    {
        return $this->options[$key] ?? null;
    }
}
