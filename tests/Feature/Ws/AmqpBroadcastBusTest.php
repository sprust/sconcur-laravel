<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Features\Amqp\Connection;
use SConcur\Features\Amqp\ConnectionOptions;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Ws\Bus\AmqpBroadcastBus;
use SConcur\Laravel\Ws\Bus\BroadcastMessageDto;
use SConcur\Laravel\Ws\WsBusOptions;

/**
 * Integration test against the live broker the compose file raises.
 *
 * The publish is made from inside shouldContinue(), on the second turn of the subscriber
 * loop — which means it lands after the first idle wake has already cancelled and
 * reopened the consumer. That is the point of the test as much as the delivery is: with
 * an auto-deleting queue the broker drops it in exactly that gap, and the reopen fails
 * with a 404 that takes the channel down with it.
 */
class AmqpBroadcastBusTest extends BaseTestCase
{
    /** Guards the loop against hanging the suite if nothing is ever delivered. */
    private const int MAX_TURNS = 8;

    private string $exchange;

    protected function setUp(): void
    {
        parent::setUp();

        $this->exchange = 'sconcur-ws-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $connection = new Connection(ConnectionOptions::fromDsn($this->dsn()));

        $connection->channel()->exchange($this->exchange)->delete();

        $connection->close();

        parent::tearDown();
    }

    #[Test]
    public function itDeliversAPublishedMessageAfterAnIdleWake(): void
    {
        $publisher = $this->bus();

        $subscriber = $this->bus();

        /** @var list<BroadcastMessageDto> $received */
        $received = [];

        $turns = 0;

        $subscriber->subscribe(
            handler: static function (BroadcastMessageDto $message) use (&$received): void {
                $received[] = $message;
            },
            shouldContinue: function () use (&$received, &$turns, $publisher): bool {
                ++$turns;

                if ($received !== []) {
                    return false;
                }

                if ($turns > self::MAX_TURNS) {
                    return false;
                }

                // The second turn is reached only through an idle wake, so the queue has
                // already outlived one cancelled consumer by the time this publishes.
                if ($turns === 2) {
                    $publisher->publish(new BroadcastMessageDto(
                        channels: ['private-orders.7'],
                        event: 'OrderShipped',
                        data: '{"id":7}',
                        socket: '1.1',
                    ));
                }

                return true;
            },
        );

        self::assertCount(1, $received);
        self::assertSame(['private-orders.7'], $received[0]->channels);
        self::assertSame('OrderShipped', $received[0]->event);
        self::assertSame('{"id":7}', $received[0]->data);
        self::assertSame('1.1', $received[0]->socket);
    }

    /** A frame someone else published must not take the worker down. */
    #[Test]
    public function itIgnoresAMessageItCannotDecode(): void
    {
        $delivered = 0;

        $turns = 0;

        $this->bus()->subscribe(
            handler: static function () use (&$delivered): void {
                ++$delivered;
            },
            shouldContinue: function () use (&$turns): bool {
                ++$turns;

                if ($turns === 2) {
                    $this->publishRaw('not a broadcast at all');
                }

                return $turns <= 3;
            },
        );

        self::assertSame(0, $delivered);
    }

    private function bus(): AmqpBroadcastBus
    {
        return new AmqpBroadcastBus(
            options: new WsBusOptions(
                dsn: $this->dsn(),
                exchange: $this->exchange,
                // Short, so the idle wake the test relies on comes round quickly.
                readTimeoutSeconds: 1.0,
                reopenBackoffMs: 100,
            ),
        );
    }

    private function publishRaw(string $body): void
    {
        $connection = new Connection(ConnectionOptions::fromDsn($this->dsn()));

        $connection->channel()->exchange($this->exchange)->publish($body);

        $connection->close();
    }

    private function dsn(): string
    {
        return (string) env('SCONCUR_RABBITMQ_DSN');
    }
}
