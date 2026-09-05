<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Ws\WsOptions;
use SConcur\Laravel\Ws\WsPresenceOptions;

class WsOptionsTest extends BaseTestCase
{
    /**
     * The extension compares the upgrade path without the query string, and so must
     * this: Echo connects to /app/{key}?protocol=7&client=js, and a check that kept the
     * query would refuse every real client.
     */
    #[Test]
    public function theQueryStringIsNotPartOfThePath(): void
    {
        $options = $this->wsOptions();

        self::assertTrue($options->acceptsPath('/app/testkey'));
        self::assertTrue($options->acceptsPath('/app/testkey?protocol=7&client=js&version=8.4.0'));
        self::assertTrue($options->acceptsPath('/app/testkey/'));
    }

    #[Test]
    public function itRefusesAnotherKeyOrAnotherPrefix(): void
    {
        $options = $this->wsOptions();

        self::assertFalse($options->acceptsPath('/app/otherkey'));
        self::assertFalse($options->acceptsPath('/'));
        self::assertFalse($options->acceptsPath('/ws/testkey'));
    }

    /**
     * A member list kept in one process is correct only while there is one process; the
     * pool size is the only thing that decides the right store.
     */
    #[Test]
    public function autoPicksTheStoreFromThePoolSize(): void
    {
        $presence = new WsPresenceOptions();

        self::assertSame(WsPresenceOptions::STORE_MEMORY, $presence->resolveStore(1));
        self::assertSame(WsPresenceOptions::STORE_CACHE, $presence->resolveStore(2));
    }

    #[Test]
    public function anExplicitStoreWinsOverAuto(): void
    {
        $presence = new WsPresenceOptions(store: WsPresenceOptions::STORE_MEMORY);

        self::assertSame(WsPresenceOptions::STORE_MEMORY, $presence->resolveStore(4));
    }

    #[Test]
    public function itIsNotConfiguredWithoutAKeyAndASecret(): void
    {
        self::assertFalse(WsOptions::fromArray([])->isConfigured());
        self::assertFalse(WsOptions::fromArray(['app_key' => 'k'])->isConfigured());
        self::assertTrue($this->wsOptions()->isConfigured());
    }

    #[Test]
    public function itReadsTheWorkbenchConfig(): void
    {
        $options = WsOptions::fromArray((array) config('sconcur.ws'));

        self::assertSame('testkey', $options->appKey);
        self::assertSame('/app/testkey', $options->connectionPath());
        self::assertSame(3, $options->maxChannelsPerConnection);
    }

    private function wsOptions(): WsOptions
    {
        return WsOptions::fromArray([
            'app_key'     => 'testkey',
            'app_secret'  => 'testsecret',
            'path_prefix' => '/app',
        ]);
    }
}
