<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Concurrency;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Translation\Translator;
use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\View\AsyncViewFactory;

/**
 * The promise the package is built on: two coroutines in one process do not see each
 * other's request state. Every adapter keeps its state in the coroutine context, so
 * each of these writes from one coroutine and reads from the other while both are
 * alive.
 *
 * A regression here leaks one request's data into another's response and does it
 * silently, which is why these interleave rather than run one unit after the other:
 * state that survives a coroutine's end would pass a sequential test.
 */
class AdapterIsolationTest extends BaseConcurrencyTestCase
{
    #[Test]
    public function configWritesBelongToTheCoroutineThatMadeThem(): void
    {
        $config = $this->getApp()->make(ConfigRepository::class);

        $config->set('demo.value', 'base');

        $seen = [];

        $this->interleave([
            function () use ($config, &$seen): void {
                $config->set('demo.value', 'first');

                $this->yieldToOthers();

                $seen['first'] = $config->get('demo.value');
            },
            function () use ($config, &$seen): void {
                $config->set('demo.value', 'second');

                $this->yieldToOthers();

                $seen['second'] = $config->get('demo.value');
            },
        ]);

        self::assertSame(['first' => 'first', 'second' => 'second'], $seen);
    }

    /** A coroutine that wrote nothing reads the shared base, not a neighbour's overlay. */
    #[Test]
    public function aCoroutineWithoutAnOverlayReadsTheBase(): void
    {
        $config = $this->getApp()->make(ConfigRepository::class);

        $config->set('demo.value', 'base');

        $seen = null;

        $this->interleave([
            function () use ($config): void {
                $config->set('demo.value', 'written');

                $this->yieldToOthers();
            },
            function () use ($config, &$seen): void {
                $this->yieldToOthers();

                $seen = $config->get('demo.value');
            },
        ]);

        self::assertSame('base', $seen);
        self::assertSame('base', $config->get('demo.value'));
    }

    #[Test]
    public function theLocaleIsPerCoroutine(): void
    {
        $translator = $this->getApp()->make(Translator::class);

        $before = $translator->getLocale();

        $seen = [];

        $this->interleave([
            function () use ($translator, &$seen): void {
                $translator->setLocale('de');

                $this->yieldToOthers();

                $seen['first'] = $translator->getLocale();
            },
            function () use ($translator, &$seen): void {
                $translator->setLocale('fr');

                $this->yieldToOthers();

                $seen['second'] = $translator->getLocale();
            },
        ]);

        self::assertSame(['first' => 'de', 'second' => 'fr'], $seen);

        // Outside every coroutine the locale is the one boot left behind: neither
        // request changed the process.
        self::assertSame($before, $translator->getLocale());
    }

    #[Test]
    public function sharedViewDataIsPerCoroutine(): void
    {
        // The configured instance the provider installed, not a fresh one: the factory
        // takes an engine resolver and a finder the container cannot build on its own.
        $view = $this->getApp()->make('view');

        assert($view instanceof AsyncViewFactory);

        $view->share('shared', 'from boot');

        $seen = [];

        $this->interleave([
            function () use ($view, &$seen): void {
                $view->share('user', 'first');

                $this->yieldToOthers();

                $shared = $view->getShared();

                $seen['first'] = [$shared['user'] ?? null, $shared['shared'] ?? null];
            },
            function () use ($view, &$seen): void {
                $view->share('user', 'second');

                $this->yieldToOthers();

                $shared = $view->getShared();

                $seen['second'] = [$shared['user'] ?? null, $shared['shared'] ?? null];
            },
        ]);

        // Each coroutine sees its own `user` and the process-wide `shared` underneath.
        self::assertSame(
            [
                'first'  => ['first', 'from boot'],
                'second' => ['second', 'from boot'],
            ],
            $seen,
        );

        self::assertArrayNotHasKey('user', $view->getShared());
    }
}
