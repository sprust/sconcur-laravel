<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws;

/**
 * The `sconcur.ws.bus` settings: how a worker reaches the other workers.
 *
 * `readTimeoutSeconds` is not a network tuning knob here — it is the subscriber's
 * heartbeat. The consumer generator only hands control back when a delivery arrives or
 * the wait times out, so this is how often a silent worker gets to notice that its last
 * connection is gone and stand down. See BusSubscriber.
 */
readonly class WsBusOptions
{
    public const string DRIVER_AMQP = 'amqp';

    public const string DRIVER_LOCAL = 'local';

    public function __construct(
        public string $driver = self::DRIVER_AMQP,
        public string $dsn = '',
        public string $exchange = 'sconcur.ws',
        public float $readTimeoutSeconds = 5.0,
        public int $reopenBackoffMs = 1000,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            driver: (string) ($config['driver'] ?? self::DRIVER_AMQP),
            dsn: (string) ($config['dsn'] ?? ''),
            exchange: (string) ($config['exchange'] ?? 'sconcur.ws'),
            readTimeoutSeconds: max(0.1, (float) ($config['read_timeout_seconds'] ?? 5.0)),
            reopenBackoffMs: max(0, (int) ($config['reopen_backoff_ms'] ?? 1000)),
        );
    }
}
