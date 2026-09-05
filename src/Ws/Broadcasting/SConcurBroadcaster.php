<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws\Broadcasting;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use SConcur\Laravel\Ws\Auth\SignatureVerifier;
use SConcur\Laravel\Ws\Bus\BroadcastBusInterface;
use SConcur\Laravel\Ws\Bus\BroadcastMessageDto;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The `sconcur` broadcast driver: the application's side of the ws pool.
 *
 * It does two unrelated things, and both are the framework's own shape. On the
 * authorization route it runs the callbacks of routes/channels.php and answers with a
 * signature the ws worker can check without a database. On broadcast() it publishes to
 * the bus — not to the ws server over HTTP, the way a Pusher-style driver would: the ws
 * server has no HTTP routes, and under SO_REUSEPORT an individual worker has no address
 * of its own.
 *
 * The channel conventions are Pusher's, taken from the framework's own trait, because the
 * client is pusher-js and the prefixes are part of the protocol.
 */
class SConcurBroadcaster extends Broadcaster
{
    use UsePusherChannelConventions;

    /**
     * The prefix that decides which of the two answers a request gets. The framework's
     * trait knows guarded from unguarded and nothing finer, so this is checked here —
     * and against the raw name, before normalizeChannelName() has taken the prefix off.
     */
    private const string PRESENCE_PREFIX = 'presence-';

    public function __construct(
        protected BroadcastBusInterface $bus,
        protected SignatureVerifier $verifier,
    ) {
    }

    /**
     * @param Request $request
     */
    public function auth($request): mixed
    {
        $rawChannel = (string) $request->input('channel_name');

        $channelName = $this->normalizeChannelName($rawChannel);

        if (
            $rawChannel === ''
            || ($this->isGuardedChannel($rawChannel) && !$this->retrieveUser($request, $channelName))
        ) {
            throw new AccessDeniedHttpException();
        }

        return parent::verifyUserCanAccessChannel($request, $channelName);
    }

    /**
     * @param Request $request
     */
    public function validAuthenticationResponse($request, mixed $result): mixed
    {
        $channelName = (string) $request->input('channel_name');

        $socketId = (string) $request->input('socket_id');

        if (!str_starts_with($channelName, self::PRESENCE_PREFIX)) {
            return [
                'auth' => $this->verifier->sign(
                    socketId: $socketId,
                    channelName: $channelName,
                ),
            ];
        }

        $user = $this->retrieveUser($request, $this->normalizeChannelName($channelName));

        // Not just a null check: retrieveUser() is untyped, and the member payload is
        // built out of whoever it answers with. A presence channel with nobody signed in
        // is a refusal, not a member with no id.
        if (!$user instanceof Authenticatable) {
            throw new AccessDeniedHttpException();
        }

        $identifier = method_exists($user, 'getAuthIdentifierForBroadcasting')
            ? $user->getAuthIdentifierForBroadcasting()
            : $user->getAuthIdentifier();

        $channelData = json_encode(
            [
                'user_id'   => $identifier,
                'user_info' => $result,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return [
            // The member payload is signed along with the channel: without it the client
            // is free to rewrite who it says it is on the way to the ws worker, which has
            // no other way to know.
            'auth'         => $this->verifier->sign(
                socketId: $socketId,
                channelName: $channelName,
                channelData: $channelData,
            ),
            'channel_data' => $channelData,
        ];
    }

    /**
     * @param array<int, mixed>    $channels
     * @param array<string, mixed> $payload
     */
    public function broadcast(array $channels, mixed $event, array $payload = []): void
    {
        // `socket` is what toOthers() puts here: the connection that raised the event and
        // must not be told about it. It travels on the message rather than in the payload,
        // because it is addressing, not data.
        $socket = Arr::pull($payload, 'socket');

        $this->bus->publish(new BroadcastMessageDto(
            channels: array_values(array_map(static fn(mixed $channel): string => (string) $channel, $channels)),
            event: (string) $event,
            data: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            socket: is_string($socket) ? $socket : null,
        ));
    }
}
