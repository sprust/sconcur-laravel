<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Adapters;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Context\Context;
use SConcur\Laravel\Config\AsyncConfig;
use SConcur\Laravel\Tests\Feature\BaseTestCase;

/**
 * The config repository is the clearest of the adapters: after boot a write belongs to
 * the coroutine that made it, while everything nobody overrode stays shared and read
 * only. Outside a coroutine the context is the process root, so one caller sees exactly
 * what the stock repository would have given.
 */
class AsyncConfigTest extends BaseTestCase
{
    /** The context key the overlay lives under; a constant of the class under test. */
    protected const string OVERLAY_KEY = 'config.overlay';

    /**
     * Outside a fiber the context is the process root, which is never released — so a
     * case that wrote an overlay would hand it to every case after it.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Context::current()->forget(self::OVERLAY_KEY);
    }

    protected function tearDown(): void
    {
        Context::current()->forget(self::OVERLAY_KEY);

        parent::tearDown();
    }

    #[Test]
    public function theProviderInstallsIt(): void
    {
        self::assertInstanceOf(AsyncConfig::class, $this->getApp()->make('config'));
    }

    #[Test]
    public function beforeBootCompletedAWriteGoesToTheSharedItems(): void
    {
        $config = new AsyncConfig(['demo' => ['value' => 'base']]);

        $config->set('demo.value', 'written');

        self::assertSame('written', $config->get('demo.value'));
        self::assertSame(['value' => 'written'], $config->all()['demo']);
    }

    #[Test]
    public function afterBootCompletedAWriteIsStillVisibleToItsOwnCaller(): void
    {
        $config = new AsyncConfig(['demo' => ['value' => 'base']]);

        $config->bootCompleted();
        $config->set('demo.value', 'overlaid');

        self::assertSame('overlaid', $config->get('demo.value'));
    }

    /**
     * all() answers with the overlay laid over the base, because that is the whole
     * configuration as its caller sees it. What must not happen is the base itself being
     * written to — so dropping the overlay has to bring the original values back.
     */
    #[Test]
    public function anOverlayDoesNotTouchTheSharedBase(): void
    {
        $config = new AsyncConfig(['demo' => ['value' => 'base', 'other' => 'kept']]);

        $config->bootCompleted();
        $config->set('demo.value', 'overlaid');

        self::assertSame(['value' => 'overlaid', 'other' => 'kept'], $config->all()['demo']);
        self::assertSame('kept', $config->get('demo.other'));

        // What a coroutine that wrote nothing reads.
        Context::current()->forget(self::OVERLAY_KEY);

        self::assertSame(['value' => 'base', 'other' => 'kept'], $config->all()['demo']);
        self::assertSame('base', $config->get('demo.value'));
    }

    #[Test]
    public function aKeyNobodyWroteFallsThroughToTheBase(): void
    {
        $config = new AsyncConfig(['demo' => ['value' => 'base']]);

        $config->bootCompleted();

        self::assertSame('base', $config->get('demo.value'));
        self::assertSame('fallback', $config->get('demo.missing', 'fallback'));
    }

    /**
     * The overlay lives in the coroutine context under a key of the class, not in the
     * instance — so two repositories in one process share it. Nothing in an application
     * builds two, but a test that builds one per case does, and the overlay of the
     * previous case would be read by the next as if it had written it. Hence the reset
     * below, and hence this test saying so out loud rather than leaving the next reader
     * to rediscover it from a confusing failure.
     */
    #[Test]
    public function theOverlayLivesInTheContextRatherThanInTheInstance(): void
    {
        $writer = new AsyncConfig(['demo' => ['value' => 'base']]);

        $writer->bootCompleted();
        $writer->set('demo.value', 'overlaid');

        $reader = new AsyncConfig(['demo' => ['value' => 'base']]);

        $reader->bootCompleted();

        self::assertSame('overlaid', $reader->get('demo.value'));
    }

    /**
     * Reading a parent of an overlaid key used to answer with the overlaid branch alone.
     * Every sibling the base had disappeared, so `config()->set('sconcur.ws.x', ...)`
     * followed by `config('sconcur.ws')` handed back a one-element array — and the code
     * reading it saw an unconfigured pool.
     */
    #[Test]
    public function overlayingALeafLeavesItsSiblingsAlone(): void
    {
        config()->set('sconcur.ws.client_events', true);

        $section = (array) config('sconcur.ws');

        self::assertTrue($section['client_events']);
        self::assertArrayHasKey('app_key', $section);
        self::assertArrayHasKey('bus', $section);
    }

    #[Test]
    public function theOverlaidLeafItselfStillReadsBack(): void
    {
        config()->set('sconcur.ws.max_channels_per_connection', 42);

        self::assertSame(42, config('sconcur.ws.max_channels_per_connection'));
        self::assertSame('testkey', config('sconcur.ws.app_key'));
    }

    /** A whole key written at once replaces the base rather than merging into it. */
    #[Test]
    public function overlayingAWholeKeyReplacesIt(): void
    {
        config()->set('sconcur.ws.presence', ['store' => 'memory']);

        self::assertSame(['store' => 'memory'], config('sconcur.ws.presence'));
    }

    /** Which matters most for lists: merging one would keep the longer base's tail. */
    #[Test]
    public function aShorterListReplacesTheLongerOne(): void
    {
        config()->set('sconcur.tasks.list', []);

        self::assertSame([], config('sconcur.tasks.list'));
    }

    /** Before boot it is the stock repository: writes land in the shared items. */
    #[Test]
    public function beforeBootItBehavesLikeTheOrdinaryRepository(): void
    {
        $config = new AsyncConfig(['a' => ['x' => 1, 'y' => 2]]);

        $config->set('a.x', 9);

        self::assertSame(['x' => 9, 'y' => 2], $config->get('a'));
        self::assertTrue($config->has('a.y'));
        self::assertSame(['a' => ['x' => 9, 'y' => 2]], $config->all());
    }

    #[Test]
    public function afterBootTheOverlayIsVisibleToHasAndAll(): void
    {
        $config = new AsyncConfig(['a' => ['x' => 1]]);

        $config->bootCompleted();
        $config->set('a.z', 3);

        self::assertTrue($config->has('a.z'));
        self::assertFalse($config->has('a.nope'));
        self::assertSame(['a' => ['x' => 1, 'z' => 3]], $config->all());
    }

    #[Test]
    public function severalKeysCanBeAskedAtOnce(): void
    {
        $config = new AsyncConfig(['a' => 1]);

        $config->bootCompleted();
        $config->set('b', 2);

        self::assertSame(['a' => 1, 'b' => 2, 'c' => 'fallback'], $config->getMany([
            'a',
            'b',
            'c' => 'fallback',
        ]));
    }

    #[Test]
    public function anUntouchedKeyReadsStraightOffTheBase(): void
    {
        $config = new AsyncConfig(['a' => 1]);

        $config->bootCompleted();

        self::assertSame(1, $config->get('a'));
        self::assertSame('fallback', $config->get('nope', 'fallback'));
    }

    /** A scalar under an overlaid branch is returned as the overlay left it. */
    #[Test]
    public function aScalarBaseIsReplacedRatherThanMerged(): void
    {
        $config = new AsyncConfig(['a' => 'scalar']);

        $config->bootCompleted();
        $config->set('a.deep', 1);

        self::assertSame(['deep' => 1], $config->get('a'));
    }
}
