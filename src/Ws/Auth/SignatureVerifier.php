<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws\Auth;

/**
 * Both halves of the channel signature: the http worker signs, the ws worker verifies.
 *
 * They are in one class on purpose. The signed string is a positional join of three
 * values, and the two sides agreeing on it is the whole of the authorization — split
 * across two files, a change to one of them is a private channel anybody can subscribe
 * to, and nothing fails until it does.
 */
readonly class SignatureVerifier
{
    public function __construct(
        private string $appKey,
        private string $appSecret,
    ) {
    }

    /**
     * The `auth` value of the subscription: the app key and the signature over
     * "socketId:channel" — plus the channel data as a third segment for a presence
     * channel, because the member payload is what the client would otherwise be free to
     * rewrite.
     */
    public function sign(string $socketId, string $channelName, ?string $channelData = null): string
    {
        return $this->appKey . ':' . $this->signature(
            socketId: $socketId,
            channelName: $channelName,
            channelData: $channelData,
        );
    }

    public function verify(string $socketId, string $channelName, string $auth, ?string $channelData = null): bool
    {
        $expected = $this->sign(
            socketId: $socketId,
            channelName: $channelName,
            channelData: $channelData,
        );

        return hash_equals($expected, $auth);
    }

    public function appKey(): string
    {
        return $this->appKey;
    }

    public function isConfigured(): bool
    {
        return ($this->appKey !== '') && ($this->appSecret !== '');
    }

    private function signature(string $socketId, string $channelName, ?string $channelData): string
    {
        $payload = $socketId . ':' . $channelName;

        if ($channelData !== null) {
            $payload .= ':' . $channelData;
        }

        return hash_hmac('sha256', $payload, $this->appSecret);
    }
}
