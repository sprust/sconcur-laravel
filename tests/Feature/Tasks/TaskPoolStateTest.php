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
     * Characterisation, not endorsement: a relaunch request is only read inside a task's
     * own loop (TaskPool runs `while ($state->isActive($name))`), and a deactivated task
     * no longer has one. So `sconcur:tasks:restart --task=NAME` does not undo
     * `sconcur:tasks:stop --task=NAME` — the pool has to be rolled to get the task back.
     *
     * The README reads as though stop and restart were a pair. Whichever way that is
     * settled — the pool learning to reactivate, or the docs saying what happens — this
     * test is the one to update, and until then it keeps the behaviour from drifting
     * unnoticed.
     */
    #[Test]
    public function aRelaunchDoesNotReactivateAStoppedTask(): void
    {
        $state = new TaskPoolState(['first']);

        $state->deactivate('first');
        $state->requestRelaunch('first');

        self::assertFalse($state->isActive('first'));
    }
}
