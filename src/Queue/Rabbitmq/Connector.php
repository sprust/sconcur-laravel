<?php

declare(strict_types=1);

namespace SConcur\Laravel\Queue\Rabbitmq;

use Illuminate\Queue\Connectors\ConnectorInterface;
use SConcur\Features\Amqp\Connection;

/**
 * Builds the queue from a `config/queue.php` connection entry, registered under the
 * `sconcur_rabbitmq` driver name.
 *
 * The connection opens lazily — the constructor touches nothing, the first command
 * raises it — so building a queue costs no socket, which matters because Laravel
 * resolves the connection whether or not anything is dispatched.
 */
readonly class Connector implements ConnectorInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function connect(array $config): Queue
    {
        return new Queue(
            connection: new Connection((string) ($config['dsn'] ?? '')),
            default: (string) ($config['queue'] ?? 'default'),
            confirmPublishes: (bool) ($config['confirm_publishes'] ?? false),
            confirmTimeoutSeconds: (float) ($config['confirm_timeout_seconds'] ?? 5.0),
        );
    }
}
