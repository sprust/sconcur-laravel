<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Tasks;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SConcur\Laravel\Tasks\TaskRegistry;
use SConcur\Laravel\Tasks\TickResultEnum;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use Workbench\App\Tasks\CountingTask;
use Workbench\App\Tasks\FailingTask;

class TaskRegistryTest extends BaseTestCase
{
    #[Test]
    public function itReadsTheTaskListInConfigOrder(): void
    {
        self::assertSame(['counting', 'idle'], $this->registry()->names());
    }

    #[Test]
    public function itResolvesATaskOutOfTheContainer(): void
    {
        $task = $this->registry()->task('counting');

        self::assertInstanceOf(CountingTask::class, $task);
        self::assertSame($this->getApp()->make(CountingTask::class), $task);
    }

    /**
     * A task carries state between ticks, so the instance is kept rather than rebuilt
     * per tick. That is what makes `sconcur:tasks:restart --task=NAME` meaningful: it
     * drops the instance and nothing else.
     */
    #[Test]
    public function itKeepsTheInstanceUntilItIsForgotten(): void
    {
        $registry = $this->failingRegistry();

        $first = $registry->task('failing');

        self::assertSame($first, $registry->task('failing'));

        $registry->forget('failing');

        self::assertNotSame($first, $registry->task('failing'));
    }

    /**
     * What forget() actually does is ask the container again — so a task the application
     * registered as a singleton comes back the same object, and restarting it does not
     * reset the state it holds. Worth knowing before writing a task that counts on being
     * rebuilt.
     */
    #[Test]
    public function aTaskBoundAsASingletonSurvivesBeingForgotten(): void
    {
        $registry = $this->registry();

        $first = $registry->task('counting');

        $registry->forget('counting');

        self::assertSame($first, $registry->task('counting'));
    }

    /**
     * The outcome of a tick is what picks the pause after it, which is the whole of the
     * contract between a task and the pool.
     */
    #[Test]
    public function itCarriesThePausesOfEachTask(): void
    {
        $definition = $this->registry()->definition('idle');

        self::assertSame(2.0, $definition->idle);
        self::assertSame(1.0, $definition->busy);
        self::assertSame(3.0, $definition->backoff);

        self::assertSame(2.0, $definition->intervalFor(TickResultEnum::Idle));
        self::assertSame(1.0, $definition->intervalFor(TickResultEnum::Worked));
        self::assertSame(3.0, $definition->intervalFor(TickResultEnum::Failed));
    }

    #[Test]
    public function itRefusesAnUnknownTask(): void
    {
        $this->expectException(RuntimeException::class);

        $this->registry()->definition('nothing-declares-this');
    }

    /**
     * Two tasks under one name would make every control command ambiguous, so the
     * registry refuses the list outright rather than picking one.
     */
    #[Test]
    public function itRefusesTwoTasksUnderOneName(): void
    {
        $this->expectException(RuntimeException::class);

        new TaskRegistry(
            container: $this->getApp(),
            list: [
                ['name' => 'twice', 'task' => CountingTask::class],
                ['name' => 'twice', 'task' => CountingTask::class],
            ],
        );
    }

    protected function registry(): TaskRegistry
    {
        return $this->getApp()->make(TaskRegistry::class);
    }

    /** A registry over the one workbench task that is not bound as a singleton. */
    protected function failingRegistry(): TaskRegistry
    {
        return new TaskRegistry(
            container: $this->getApp(),
            list: [
                ['name' => 'failing', 'task' => FailingTask::class],
            ],
        );
    }
}
