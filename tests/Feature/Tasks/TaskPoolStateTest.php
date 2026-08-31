<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Tasks;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tasks\TaskPoolState;
use SConcur\Laravel\Tests\Feature\BaseTestCase;

/**
 * The pool's bookkeeping: which tasks are still ticking, which have been asked to
 * rebuild, and whether a stop is under way.
 */
class TaskPoolStateTest extends BaseTestCase
{
    #[Test]
    public function everyTaskStartsActive(): void
    {
        $state = new TaskPoolState(['first', 'second']);

        self::assertSame(['first', 'second'], $state->names());
        self::assertTrue($state->isActive('first'));
        self::assertTrue($state->isActive('second'));
        self::assertFalse($state->isStopRequested());
    }

    /** Stopping one task leaves the others ticking — that is what --task is for. */
    #[Test]
    public function deactivatingOneLeavesTheRest(): void
    {
        $state = new TaskPoolState(['first', 'second']);

        $state->deactivate('first');

        self::assertFalse($state->isActive('first'));
        self::assertTrue($state->isActive('second'));
        self::assertFalse($state->isStopRequested());
    }

    #[Test]
    public function stopAllDeactivatesEveryTask(): void
    {
        $state = new TaskPoolState(['first', 'second']);

        $state->stopAll();

        self::assertTrue($state->isStopRequested());
        self::assertFalse($state->isActive('first'));
        self::assertFalse($state->isActive('second'));
    }

    /** One request rebuilds a task once: taking it clears it. */
    #[Test]
    public function aRelaunchRequestIsTakenOnce(): void
    {
        $state = new TaskPoolState(['first']);

        self::assertFalse($state->takeRelaunch('first'));

        $state->requestRelaunch('first');

        self::assertTrue($state->takeRelaunch('first'));
        self::assertFalse($state->takeRelaunch('first'));
    }

    #[Test]
    public function runningNamesDropsWhatHasStopped(): void
    {
        $state = new TaskPoolState(['first', 'second']);

        self::assertSame(['first', 'second'], $state->runningNames());

        $state->markStopped('first');

        self::assertSame(['second'], $state->runningNames());
    }

    /**
     * A parked task can be put back into service, which is what makes
     * `sconcur:tasks:restart --task=NAME` able to undo `sconcur:tasks:stop --task=NAME`.
     * It used to be unable to: deactivating ended the task's coroutine, and the relaunch
     * request is only read from inside that coroutine's loop.
     */
    #[Test]
    public function aParkedTaskCanBeActivatedAgain(): void
    {
        $state = new TaskPoolState(['first']);

        $state->deactivate('first');

        self::assertFalse($state->isActive('first'));

        $state->activate('first');

        self::assertTrue($state->isActive('first'));
    }

    /**
     * With every task parked there is nothing left to tick, and the controller ends the
     * pool on that — the way it always did when tasks were stopped one by one.
     */
    #[Test]
    public function activeNamesIsEmptyWhenEveryTaskIsParked(): void
    {
        $state = new TaskPoolState(['first', 'second']);

        self::assertSame(['first', 'second'], $state->activeNames());

        $state->deactivate('first');

        self::assertSame(['second'], $state->activeNames());

        $state->deactivate('second');

        self::assertSame([], $state->activeNames());
    }

    /**
     * A parked task waits on its own interrupt: the ticking one fires on exactly the
     * condition a parked task is already in, so waiting on it would spin.
     */
    #[Test]
    public function aParkedTaskWakesOnARelaunchAStopOrBeingActivated(): void
    {
        $state = new TaskPoolState(['first']);

        $state->deactivate('first');

        $wake = $state->parkInterruptFor('first');

        self::assertFalse($wake(), 'a parked task with nothing to come back for keeps waiting');

        $state->requestRelaunch('first');

        self::assertTrue($wake());

        $state->takeRelaunch('first');

        self::assertFalse($wake());

        $state->stopAll();

        self::assertTrue($wake());
    }
}
