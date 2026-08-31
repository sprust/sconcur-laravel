<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Concurrency;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use SConcur\Context\Context;
use SConcur\Laravel\Foundation\AsyncApplication;
use SConcur\Laravel\Foundation\ScopedService;
use SConcur\Laravel\Foundation\ScopedServiceProxy;

/**
 * The container half of the same promise: a service the framework treats as a singleton
 * — the request, the session, the auth manager, the cookie jar — is resolved once per
 * coroutine instead of once per process.
 *
 * Testbench builds a plain Illuminate application, so these drive an AsyncApplication of
 * their own. That is the class under test anyway; what the surrounding app is does not
 * enter into it.
 */
class ContainerIsolationTest extends BaseConcurrencyTestCase
{
    /**
     * Published into the context the way LaravelHttpHandler does it, not through
     * instance(): that writes to the shared container, which is the very thing the
     * request must not go through.
     */
    #[Test]
    public function eachCoroutineResolvesItsOwnRequest(): void
    {
        $app = $this->asyncApplication();

        $seen = [];

        $this->interleave([
            function () use ($app, &$seen): void {
                Context::current()->set(ScopedService::REQUEST->value, Request::create('/first'), replace: true);

                $this->yieldToOthers();

                $seen['first'] = $app->make('request')->getRequestUri();
            },
            function () use ($app, &$seen): void {
                Context::current()->set(ScopedService::REQUEST->value, Request::create('/second'), replace: true);

                $this->yieldToOthers();

                $seen['second'] = $app->make('request')->getRequestUri();
            },
        ]);

        self::assertSame(['first' => '/first', 'second' => '/second'], $seen);
    }

    /**
     * A scoped binding is built once per coroutine and kept for the rest of it: two
     * resolves inside one coroutine are the same object, and the neighbour's is not.
     */
    #[Test]
    public function aScopedBindingIsOneInstancePerCoroutine(): void
    {
        $app = $this->asyncApplication();

        $built = 0;

        $app->scopedSingleton('demo.scoped', static function () use (&$built): object {
            $built++;

            return new class() {
                public string $owner = '';
            };
        });

        $seen = [];

        $this->interleave([
            function () use ($app, &$seen): void {
                $app->make('demo.scoped')->owner = 'first';

                $this->yieldToOthers();

                $seen['first'] = $app->make('demo.scoped')->owner;
            },
            function () use ($app, &$seen): void {
                $app->make('demo.scoped')->owner = 'second';

                $this->yieldToOthers();

                $seen['second'] = $app->make('demo.scoped')->owner;
            },
        ]);

        self::assertSame(['first' => 'first', 'second' => 'second'], $seen);
        self::assertSame(2, $built, 'the factory must run once per coroutine');
    }

    /**
     * Outside a coroutine there is one caller, so a scoped service is a single instance
     * — which is what the container would have given anyway. It is kept in a store of
     * the application's own rather than in the context: outside a fiber the context is
     * the process root, which every coroutine reads through to, so an instance built
     * during bootstrap would otherwise be shared by every request in the process.
     */
    #[Test]
    public function outsideACoroutineAScopedBindingIsASingleInstance(): void
    {
        $app = $this->asyncApplication();

        $app->scopedSingleton('demo.scoped', static fn(): object => new class() {
        });

        self::assertSame($app->make('demo.scoped'), $app->make('demo.scoped'));
    }

    /**
     * The facade accessors go through a proxy on purpose: a facade caches the instance
     * it resolved in a static shared by the whole process, so without it the first
     * coroutine to touch Cookie:: would pin its own jar there for every later one, and a
     * queued cookie would be flushed onto somebody else's response.
     */
    #[Test]
    public function facadeAccessorsResolveThroughAProxy(): void
    {
        $app = $this->asyncApplication();

        foreach (['auth', 'session', 'cookie'] as $alias) {
            self::assertInstanceOf(ScopedServiceProxy::class, $app[$alias], $alias . ' is not proxied');
        }
    }

    /**
     * bound('request') answers true even before any request exists, so bootstrap code
     * asking the container about it does not crash.
     */
    #[Test]
    public function theRequestIsAlwaysBound(): void
    {
        self::assertTrue($this->asyncApplication()->bound('request'));
    }

    protected function asyncApplication(): AsyncApplication
    {
        $app = new AsyncApplication(base_path());

        $app->singleton('config', fn() => $this->getApp()->make('config'));

        return $app;
    }
}
