<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Tasks;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Exceptions\Handler;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SConcur\Laravel\Tasks\Control\ControlActionEnum;
use SConcur\Laravel\Tasks\Control\ControlChannel;
use SConcur\Laravel\Tasks\Events\TaskTickFinished;
use SConcur\Laravel\Tasks\Events\TaskTickStarted;
use SConcur\Laravel\Tasks\TaskPool;
use SConcur\Laravel\Tasks\TaskPoolLogger;
use SConcur\Laravel\Tasks\TaskRegistry;
use SConcur\Laravel\Tasks\TickResultEnum;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use Throwable;
use Workbench\App\Tasks\CountingTask;
use Workbench\App\Tasks\FailingTask;

/**
 * The pair of events around every tick: what the application listens to instead of
 * wrapping each of its own tasks in the same try/catch.
 *
 * The pool is run for real rather than having tick() called on it, because what the
 * events have to answer for is the loop around the tick — that the second one arrives
 * when the tick throws as well, and that a listener of either cannot take the pool down.
 */
class TaskPoolEventsTest extends BaseTestCase
{
    protected string $logPath;

    /** @var list<TaskTickStarted|TaskTickFinished> */
    protected array $events = [];

    /**
     * What the pool reported, instead of letting testbench log it.
     *
     * @var list<Throwable>
     */
    protected array $reported = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->logPath = (string) tempnam(sys_get_temp_dir(), 'sconcur-task-pool-events-log');

        $this->getApp()->instance(TaskPoolLogger::class, new TaskPoolLogger(stream: $this->logPath));

        $record = function (Throwable $exception): void {
            $this->reported[] = $exception;
        };

        $this->getApp()->instance(
            ExceptionHandler::class,
            new class($this->getApp(), $record) extends Handler {
                /**
                 * @param Closure(Throwable): void $record
                 */
                public function __construct(Container $container, protected Closure $record)
                {
                    parent::__construct($container);
                }

                public function report(Throwable $e): void
                {
                    ($this->record)($e);
                }
            },
        );

        $this->listen(function (TaskTickStarted|TaskTickFinished $event): void {
            $this->events[] = $event;
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);

        parent::tearDown();
    }

    #[Test]
    public function aTickThatReturnsRaisesBothEventsWithNoException(): void
    {
        $exitCode = $this->runPool(name: 'counting', task: CountingTask::class, ticks: 2);

        self::assertSame(0, $exitCode);

        $started  = $this->events[0];
        $finished = $this->events[1];

        self::assertInstanceOf(TaskTickStarted::class, $started);
        self::assertSame('counting', $started->name);

        self::assertInstanceOf(TaskTickFinished::class, $finished);
        self::assertSame('counting', $finished->name);
        self::assertSame(TickResultEnum::Worked, $finished->result);
        self::assertNull($finished->exception);
    }

    /** The point of the pair: a failed tick is reported through the same two events. */
    #[Test]
    public function aTickThatThrowsCarriesTheExceptionIntoTheFinishedEvent(): void
    {
        $exitCode = $this->runPool(name: 'failing', task: FailingTask::class, ticks: 2);

        self::assertSame(0, $exitCode);

        $finished = $this->events[1];

        self::assertInstanceOf(TaskTickFinished::class, $finished);
        self::assertSame('failing', $finished->name);
        self::assertSame(TickResultEnum::Failed, $finished->result);
        self::assertInstanceOf(RuntimeException::class, $finished->exception);
        self::assertSame('the failing task always throws', $finished->exception->getMessage());
    }

    /** Every tick is a pair, and they alternate — no started without its finished. */
    #[Test]
    public function theEventsAlternateOverSeveralTicks(): void
    {
        $this->runPool(name: 'counting', task: CountingTask::class, ticks: 3);

        $classes = array_map(static fn(object $event): string => $event::class, $this->events);

        self::assertSame(
            [
                TaskTickStarted::class,
                TaskTickFinished::class,
                TaskTickStarted::class,
                TaskTickFinished::class,
                TaskTickStarted::class,
                TaskTickFinished::class,
            ],
            array_slice($classes, 0, 6),
        );
    }

    /**
     * A listener is application code, and the pool's contract is that application code
     * cannot stop it: an exception escaping dispatch() would leave the tick and
     * WaitGroup::iterate() would unwind every other task with it.
     */
    #[Test]
    public function aThrowingListenerIsReportedAndTheTicksCarryOn(): void
    {
        $this->listen(static function (TaskTickStarted $event): void {
            throw new RuntimeException('the listener of ' . $event->name . ' always throws');
        });

        $exitCode = $this->runPool(name: 'counting', task: CountingTask::class, ticks: 3);

        self::assertSame(0, $exitCode);
        self::assertGreaterThanOrEqual(3, $this->getApp()->make(CountingTask::class)->ticks);

        $messages = array_map(
            static fn(Throwable $exception): string => $exception->getMessage(),
            $this->reported,
        );

        self::assertContains('the listener of counting always throws', $messages);
        self::assertStringContainsString('listener of ' . TaskTickStarted::class . ' failed', $this->log());
    }

    /**
     * @param class-string $task
     */
    protected function runPool(string $name, string $task, int $ticks): int
    {
        $this->getApp()->instance(
            TaskRegistry::class,
            new TaskRegistry(
                container: $this->getApp(),
                list: [
                    [
                        'name'    => $name,
                        'task'    => $task,
                        'idle'    => 0.01,
                        'busy'    => 0.01,
                        'backoff' => 0.01,
                    ],
                ],
            ),
        );

        $this->stopAfter($ticks);

        return $this->getApp()->make(TaskPool::class)->run();
    }

    /**
     * Stops the pool from a listener once it has ticked enough times, so the test runs
     * the real loop and still ends by itself.
     */
    protected function stopAfter(int $ticks): void
    {
        $seen = 0;

        $channel = $this->getApp()->make(ControlChannel::class);

        $this->listen(static function (TaskTickFinished $event) use (&$seen, $ticks, $channel): void {
            if (++$seen !== $ticks) {
                return;
            }

            $channel->send(ControlActionEnum::Stop);
        });
    }

    protected function listen(Closure $listener): void
    {
        $this->getApp()->make(Dispatcher::class)->listen($listener);
    }

    protected function log(): string
    {
        return (string) file_get_contents($this->logPath);
    }
}
