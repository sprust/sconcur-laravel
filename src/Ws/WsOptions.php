<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws;

/**
 * The `sconcur.ws` settings — everything the protocol side of the pool runs on. The
 * network side is the group's `server` block, which travels through argv instead.
 */
readonly class WsOptions
{
    public function __construct(
        public string $appKey,
        public string $appSecret,
        public string $pathPrefix,
        public int $activityTimeoutSeconds,
        public int $maxChannelsPerConnection,
        public bool $clientEvents,
        public int $clientEventsPerMinute,
        public WsBusOptions $bus,
        public WsPresenceOptions $presence,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            appKey: (string) ($config['app_key'] ?? ''),
            appSecret: (string) ($config['app_secret'] ?? ''),
            pathPrefix: rtrim((string) ($config['path_prefix'] ?? '/app'), '/'),
            activityTimeoutSeconds: max(10, (int) ($config['activity_timeout_seconds'] ?? 120)),
            maxChannelsPerConnection: max(1, (int) ($config['max_channels_per_connection'] ?? 100)),
            clientEvents: (bool) ($config['client_events'] ?? false),
            clientEventsPerMinute: max(0, (int) ($config['client_events_per_minute'] ?? 60)),
            bus: WsBusOptions::fromArray((array) ($config['bus'] ?? [])),
            presence: WsPresenceOptions::fromArray((array) ($config['presence'] ?? [])),
        );
    }

    /** The connection path this server answers on: the prefix plus the app key. */
    public function connectionPath(): string
    {
        return $this->pathPrefix . '/' . $this->appKey;
    }

    /**
     * Whether the path a client upgraded on belongs to this server. The query string is
     * not part of it — the extension compares paths without one, and so does this.
     */
    public function acceptsPath(string $path): bool
    {
        $path = explode('?', $path, 2)[0];

        return rtrim($path, '/') === $this->connectionPath();
    }

    public function isConfigured(): bool
    {
        return ($this->appKey !== '') && ($this->appSecret !== '');
    }
}
