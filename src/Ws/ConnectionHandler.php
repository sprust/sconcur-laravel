<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws;

use Illuminate\Contracts\Events\Dispatcher;
use JsonException;
use SConcur\Exceptions\WsServer\WsServerConnectionClosedException;
use SConcur\Features\WsServer\Dto\Connection;
use SConcur\Laravel\Ws\Auth\SignatureVerifier;
use SConcur\Laravel\Ws\Bus\BroadcastBusInterface;
use SConcur\Laravel\Ws\Bus\BroadcastMessageDto;
use SConcur\Laravel\Ws\Bus\BusSubscriber;
use SConcur\Laravel\Ws\Events\ChannelSubscribed;
use SConcur\Laravel\Ws\Events\ChannelUnsubscribed;
use SConcur\Laravel\Ws\Events\ClientEventReceived;
use SConcur\Laravel\Ws\Events\ConnectionClosed;
use SConcur\Laravel\Ws\Events\ConnectionOpened;
use SConcur\Laravel\Ws\Presence\PresencePayload;
use SConcur\Laravel\Ws\Presence\PresenceRepositoryInterface;
use SConcur\Laravel\Ws\Protocol\ChannelName;
use SConcur\Laravel\Ws\Protocol\ErrorCodeEnum;
use SConcur\Laravel\Ws\Protocol\IncomingMessageDto;
use SConcur\Laravel\Ws\Protocol\MessageCodec;
use SConcur\Laravel\Ws\Protocol\ProtocolEventEnum;
use Throwable;

/**
 * One client, from the upgrade to the close. Runs in the connection's own coroutine.
 *
 * Everything it decides is decided from the frame and the signature: there is no session
 * here, no database and no authenticated user. The right to a private channel was granted
 * by an http worker, which signed it, and this side only checks that the signature holds.
 * That is what keeps a ws worker a router of messages rather than a second application.
 */
readonly class ConnectionHandler
{
    public function __construct(
        private WsOptions $options,
        private ConnectionRegistry $registry,
        private SignatureVerifier $verifier,
        private MessageCodec $codec,
        private BroadcastBusInterface $bus,
        private BusSubscriber $subscriber,
        private PresenceRepositoryInterface $presence,
        private PresencePayload $presencePayload,
        private SocketIdGenerator $socketIds,
        private Dispatcher $events,
        private WsLogger $logger,
    ) {
    }

    private function teardown(ConnectionState $state): void
    {
        $channels = $state->channels();

        try {
            foreach ($channels as $channel) {
                $this->leaveChannel($state, ChannelName::fromString($channel));
            }
        } catch (Throwable $exception) {
            // Leaving a presence channel talks to the shared store, and that store can be
            // down. The registry has to be cleared anyway: an entry left behind is a dead
            // socket the fan-out keeps writing to, and — because the bus subscriber lives
            // exactly as long as the registry is not empty — a coroutine that never ends
            // and a graceful stop that never finishes.
            $this->logger->log('conn', 'teardown failed for ' . $state->socketId . ': ' . $exception->getMessage());
        } finally {
            $this->registry->forget($state->socketId);
        }

        $this->events->dispatch(new ConnectionClosed(
            socketId: $state->socketId,
            channels: $channels,
        ));
    }

    private function handleFrame(ConnectionState $state, string $raw): void
    {
        $message = $this->codec->decode($raw);

        if ($message === null) {
            return;
        }

        if (ProtocolEventEnum::isClientEvent($message->event)) {
            $this->handleClientEvent($state, $message);

            return;
        }

        match (ProtocolEventEnum::tryFrom($message->event)) {
            ProtocolEventEnum::Ping        => $this->write($state, $this->codec->encode(event: ProtocolEventEnum::Pong)),
            ProtocolEventEnum::Subscribe   => $this->handleSubscribe($state, $message),
            ProtocolEventEnum::Unsubscribe => $this->handleUnsubscribe($state, $message),
            // Anything else is a frame this server does not speak. Ignored rather than
            // refused: a newer client sending one must not lose its connection over it.
            default => null,
        };
    }

    private function handleSubscribe(ConnectionState $state, IncomingMessageDto $message): void
    {
        $name = $message->channelName();

        if ($name === null || $name === '') {
            return;
        }

        $channel = ChannelName::fromString($name);

        if ($channel->isEncrypted()) {
            $this->subscriptionError(
                state: $state,
                channel: $channel->raw,
                code: ErrorCodeEnum::Unauthorized,
                message: 'Encrypted channels are not supported.',
            );

            return;
        }

        if ($state->hasChannel($channel->raw)) {
            return;
        }

        if ($state->channelCount() >= $this->options->maxChannelsPerConnection) {
            $this->subscriptionError(
                state: $state,
                channel: $channel->raw,
                code: ErrorCodeEnum::OverCapacity,
                message: 'Too many channels on this connection.',
            );

            return;
        }

        $channelData = $message->stringField('channel_data');

        if ($channel->requiresAuthorization() && !$this->isAuthorized($state, $channel, $message, $channelData)) {
            $this->subscriptionError(
                state: $state,
                channel: $channel->raw,
                code: ErrorCodeEnum::Unauthorized,
                message: 'Subscription authorization failed.',
            );

            return;
        }

        $this->registry->subscribe(
            socketId: $state->socketId,
            channel: $channel->raw,
            channelData: $channelData,
        );

        if ($channel->isPresence()) {
            $this->joinPresence($state, $channel, $channelData);
        } else {
            $this->write($state, $this->codec->encode(
                event: ProtocolEventEnum::SubscriptionSucceeded,
                channel: $channel->raw,
            ));
        }

        $this->events->dispatch(new ChannelSubscribed(
            socketId: $state->socketId,
            channel: $channel->raw,
        ));
    }

    private function handleUnsubscribe(ConnectionState $state, IncomingMessageDto $message): void
    {
        $name = $message->channelName();

        if ($name === null || !$state->hasChannel($name)) {
            return;
        }

        $this->leaveChannel($state, ChannelName::fromString($name));

        $this->events->dispatch(new ChannelUnsubscribed(
            socketId: $state->socketId,
            channel: $name,
        ));
    }

    /**
     * A client-* event: one subscriber talking to the others without the application in
     * between. Off unless asked for, private and presence channels only, and rate limited
     * per connection — otherwise one client is a broadcast facility.
     */
    private function handleClientEvent(ConnectionState $state, IncomingMessageDto $message): void
    {
        if (!$this->options->clientEvents) {
            return;
        }

        $name = $message->channelName();

        if ($name === null || !$state->hasChannel($name)) {
            return;
        }

        if (!ChannelName::fromString($name)->acceptsClientEvents()) {
            return;
        }

        if (!$state->allowClientEvent($this->options->clientEventsPerMinute)) {
            return;
        }

        $data = $this->codec->encodeData($message->data);

        $this->publish(
            channel: $name,
            event: $message->event,
            data: $data,
            exceptSocketId: $state->socketId,
        );

        $this->events->dispatch(new ClientEventReceived(
            socketId: $state->socketId,
            channel: $name,
            event: $message->event,
            data: $data,
        ));
    }

    private function isAuthorized(
        ConnectionState $state,
        ChannelName $channel,
        IncomingMessageDto $message,
        ?string $channelData,
    ): bool {
        $auth = $message->stringField('auth');

        if ($auth === null) {
            return false;
        }

        return $this->verifier->verify(
            socketId: $state->socketId,
            channelName: $channel->raw,
            auth: $auth,
            // Signed for presence only, and there it is what stops the client from
            // rewriting the member payload the application authorized.
            channelData: $channel->isPresence() ? $channelData : null,
        );
    }

    private function joinPresence(ConnectionState $state, ChannelName $channel, ?string $channelData): void
    {
        $member = $this->decodeMember($channelData);

        if ($member === null) {
            $this->registry->unsubscribe(
                socketId: $state->socketId,
                channel: $channel->raw,
            );

            $this->subscriptionError(
                state: $state,
                channel: $channel->raw,
                code: ErrorCodeEnum::Unauthorized,
                message: 'Presence channel data is missing or malformed.',
            );

            return;
        }

        try {
            $before = $this->presence->members($channel->raw);

            $this->presence->join(
                channel: $channel->raw,
                socketId: $state->socketId,
                member: $member,
            );

            $members = $this->presence->members($channel->raw);
        } catch (Throwable $exception) {
            // The shared store is unreachable. Refusing this one subscription is the
            // whole of the damage: the connection keeps its other channels, and a client
            // that is told gets to retry, which is better than losing the socket over a
            // roster.
            $this->logger->log('conn', 'presence store failed on ' . $channel->raw . ': ' . $exception->getMessage());

            $this->registry->unsubscribe(
                socketId: $state->socketId,
                channel: $channel->raw,
            );

            $this->subscriptionError(
                state: $state,
                channel: $channel->raw,
                code: ErrorCodeEnum::OverCapacity,
                message: 'Presence store is unavailable.',
            );

            return;
        }

        $this->write($state, $this->codec->encode(
            event: ProtocolEventEnum::SubscriptionSucceeded,
            data: $this->presencePayload->forSubscription($members),
            channel: $channel->raw,
        ));

        $userId = $this->presencePayload->userId($member);

        if ($userId === null) {
            return;
        }

        // A second tab of the same person is not an arrival, and announcing it would add
        // a member the client already has.
        $alreadyPresent = $this->presencePayload->hasOtherConnection(
            members: $before,
            socketId: $state->socketId,
            userId: $userId,
        );

        if ($alreadyPresent) {
            return;
        }

        $this->publish(
            channel: $channel->raw,
            event: ProtocolEventEnum::MemberAdded->value,
            data: $this->codec->encodeData($this->presencePayload->forMemberAdded($member)),
            exceptSocketId: $state->socketId,
        );
    }

    private function leaveChannel(ConnectionState $state, ChannelName $channel): void
    {
        $member = $channel->isPresence() ? $this->decodeMember($state->channelData($channel->raw)) : null;

        $this->registry->unsubscribe(
            socketId: $state->socketId,
            channel: $channel->raw,
        );

        if ($member === null) {
            return;
        }

        $this->presence->leave(
            channel: $channel->raw,
            socketId: $state->socketId,
        );

        $userId = $this->presencePayload->userId($member);

        if ($userId === null) {
            return;
        }

        $remaining = $this->presence->members($channel->raw);

        $stillPresent = $this->presencePayload->hasOtherConnection(
            members: $remaining,
            socketId: $state->socketId,
            userId: $userId,
        );

        if ($stillPresent) {
            return;
        }

        $this->publish(
            channel: $channel->raw,
            event: ProtocolEventEnum::MemberRemoved->value,
            data: $this->codec->encodeData($this->presencePayload->forMemberRemoved($member)),
            exceptSocketId: $state->socketId,
        );
    }

    /**
     * @return null|array<string, mixed>
     */
    private function decodeMember(?string $channelData): ?array
    {
        if ($channelData === null || $channelData === '') {
            return null;
        }

        try {
            $decoded = json_decode($channelData, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function publish(string $channel, string $event, string $data, ?string $exceptSocketId): void
    {
        try {
            $this->bus->publish(new BroadcastMessageDto(
                channels: [$channel],
                event: $event,
                data: $data,
                socket: $exceptSocketId,
            ));
        } catch (Throwable $exception) {
            // The connection itself is fine; what failed is telling the other workers.
            $this->logger->log('conn', 'publish failed on ' . $channel . ': ' . $exception->getMessage());
        }
    }

    /**
     * A subscription that will not happen. Reported on the channel rather than as a
     * connection error: the client surfaces it to whoever asked for that channel and stays
     * connected for the others.
     */
    private function subscriptionError(
        ConnectionState $state,
        string $channel,
        ErrorCodeEnum $code,
        string $message,
    ): void {
        $this->write($state, $this->codec->encode(
            event: ProtocolEventEnum::SubscriptionError,
            data: [
                'type'   => 'AuthError',
                'error'  => $message,
                'status' => $code->value,
            ],
            channel: $channel,
        ));
    }

    /** A connection that will not be served: say why, then end it. */
    private function refuse(Connection $connection, ErrorCodeEnum $code, string $message): void
    {
        try {
            $connection->write($this->codec->encode(
                event: ProtocolEventEnum::Error,
                data: [
                    'code'    => $code->value,
                    'message' => $message,
                ],
            ));
        } catch (Throwable) {
            // Refusing a client that is already gone needs no report.
        }

        $connection->close();
    }

    private function write(ConnectionState $state, string $frame): void
    {
        $state->connection->write($frame);
    }

    public function __invoke(Connection $connection): void
    {
        // The group's `path` normally answers this with a 404 before PHP is reached. It is
        // checked again because that path may be configured as "any", and one comparison
        // is cheaper than a server whose key is enforced in only one of two places.
        if (!$this->options->acceptsPath($connection->path)) {
            $this->refuse(
                connection: $connection,
                code: ErrorCodeEnum::UnknownAppKey,
                message: 'Unknown app key.',
            );

            return;
        }

        $state = new ConnectionState(
            socketId: $this->socketIds->next(),
            connection: $connection,
        );

        $this->registry->add($state);

        try {
            $this->write($state, $this->codec->encode(
                event: ProtocolEventEnum::ConnectionEstablished,
                data: [
                    'socket_id'        => $state->socketId,
                    'activity_timeout' => $this->options->activityTimeoutSeconds,
                ],
            ));

            // The first connection is what starts the bus subscriber, and the last one to
            // leave is what lets it stand down. See BusSubscriber.
            $this->subscriber->ensureRunning();

            $this->events->dispatch(new ConnectionOpened(
                socketId: $state->socketId,
                remoteAddr: $connection->remoteAddr,
                path: $connection->path,
            ));

            while (($raw = $connection->read()) !== null) {
                $this->handleFrame($state, $raw);
            }
        } catch (WsServerConnectionClosedException) {
            // The client went away mid-frame. Ordinary, and the teardown below is the
            // whole of the response to it.
        } finally {
            $this->teardown($state);
        }
    }
}
