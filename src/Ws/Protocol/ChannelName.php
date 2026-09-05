<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws\Protocol;

/**
 * A channel name in both forms at once, because the two sides want different ones: the
 * protocol signs and routes the full name (`private-orders.7`), while Laravel's channel
 * callbacks are registered under the name with the prefix stripped (`orders.7`).
 *
 * Keeping them apart in strings is how a signature ends up computed over one form and
 * verified over the other.
 */
readonly class ChannelName
{
    private const string PRIVATE_PREFIX = 'private-';

    private const string PRESENCE_PREFIX = 'presence-';

    private const string ENCRYPTED_PREFIX = 'private-encrypted-';

    public function __construct(
        public string $raw,
        public ChannelTypeEnum $type,
        public string $short,
    ) {
    }

    public static function fromString(string $name): self
    {
        // Checked before the private prefix, which it also starts with.
        if (str_starts_with($name, self::ENCRYPTED_PREFIX)) {
            return new self(
                raw: $name,
                type: ChannelTypeEnum::Encrypted,
                short: substr($name, strlen(self::ENCRYPTED_PREFIX)),
            );
        }

        if (str_starts_with($name, self::PRIVATE_PREFIX)) {
            return new self(
                raw: $name,
                type: ChannelTypeEnum::Private,
                short: substr($name, strlen(self::PRIVATE_PREFIX)),
            );
        }

        if (str_starts_with($name, self::PRESENCE_PREFIX)) {
            return new self(
                raw: $name,
                type: ChannelTypeEnum::Presence,
                short: substr($name, strlen(self::PRESENCE_PREFIX)),
            );
        }

        return new self(
            raw: $name,
            type: ChannelTypeEnum::Public,
            short: $name,
        );
    }

    public function requiresAuthorization(): bool
    {
        return $this->type->requiresAuthorization();
    }

    public function isPresence(): bool
    {
        return $this->type === ChannelTypeEnum::Presence;
    }

    public function isEncrypted(): bool
    {
        return $this->type === ChannelTypeEnum::Encrypted;
    }

    /** Whether a client event may be sent on this channel: private and presence only. */
    public function acceptsClientEvents(): bool
    {
        return ($this->type === ChannelTypeEnum::Private) || ($this->type === ChannelTypeEnum::Presence);
    }
}
