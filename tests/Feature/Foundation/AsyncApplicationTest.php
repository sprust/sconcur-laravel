<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Foundation;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SConcur\Laravel\Events\AsyncDispatcher;
use SConcur\Laravel\Foundation\AsyncApplication;
use SConcur\Laravel\Routing\AsyncRouter;
use SConcur\Laravel\SConcurServiceProvider;

/**
 * The dispatcher and the router are installed by the container's own constructor, not by
 * the provider — and this is what that buys.
 *
 * The rest of the suite runs on an application testbench builds through
 * workbench/bootstrap/app.php, already wired; these cases build the container by hand, the
 * way an application's own bootstrap/app.php does, and watch what a kernel sees.
 */
class AsyncApplicationTest extends TestCase
{
    #[Test]
    public function itInstallsTheAdaptersInItsConstructor(): void
    {
        $app = new AsyncApplication(__DIR__);

        self::assertInstanceOf(AsyncDispatcher::class, $app->make('events'));
        self::assertInstanceOf(AsyncRouter::class, $app->make('router'));
    }

    /**
     * The stock binding is not `new Dispatcher($app)` — Illuminate\Events\EventServiceProvider
     * hands it a queue resolver and a transaction manager resolver, and a replacement that
     * drops them breaks a ShouldQueue listener and dispatches an afterCommit event before
     * the commit.
     */
    #[Test]
    public function itKeepsTheResolversTheStockDispatcherIsGiven(): void
    {
        $dispatcher = (new AsyncApplication(__DIR__))->make('events');

        self::assertInstanceOf(AsyncDispatcher::class, $dispatcher);
        self::assertIsCallable($this->readProperty($dispatcher, 'queueResolver'));
        self::assertIsCallable($this->readProperty($dispatcher, 'transactionManagerResolver'));
    }

    /**
     * The reason the constructor is the seam.
     *
     * Application::handleCommand() builds the console kernel before a single provider
     * registers, and the kernel keeps the dispatcher it was handed: it is the one
     * Illuminate\Console\Application is built from and the one CommandStarting and
     * CommandFinished are dispatched into. A provider swapping 'events' afterwards would
     * leave the kernel dispatching into an object nobody listens on — so the provider is
     * registered here too, to pin that it leaves the binding alone.
     */
    #[Test]
    public function itHandsTheConsoleKernelTheDispatcherTheContainerKeeps(): void
    {
        $app = new AsyncApplication(__DIR__);
        $app->instance('config', new Repository([]));
        $app->singleton(ConsoleKernelContract::class, ConsoleKernel::class);

        $kernel = $app->make(ConsoleKernelContract::class);

        (new SConcurServiceProvider($app))->register();

        self::assertInstanceOf(AsyncDispatcher::class, $this->readProperty($kernel, 'events'));
        self::assertSame($app->make('events'), $this->readProperty($kernel, 'events'));
    }

    /**
     * Dispatcher::$queueResolver and Console\Kernel::$events are the state this test class
     * is about, and both are protected. Reading them is the same measurement the bug report
     * made, and there is no public way to ask either object what it holds.
     */
    private function readProperty(object $target, string $name): mixed
    {
        return (new ReflectionProperty($target, $name))->getValue($target);
    }
}
