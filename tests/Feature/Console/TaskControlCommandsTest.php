<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Console;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tasks\Control\ControlActionEnum;
use SConcur\Laravel\Tasks\Control\ControlChannel;
use SConcur\Laravel\Tasks\Control\ControlCommandDto;
use SConcur\Laravel\Tests\Feature\BaseTestCase;

/**
 * The commands that reach a running pool from another process.
 *
 * They neither look for the pool nor need its pid — they put a command in the cache and
 * the pool takes it on its controller tick. That is what lets the pool be managed from a
 * different container to the one it runs in.
 */
class TaskControlCommandsTest extends BaseTestCase
{
    #[Test]
    public function stopWithoutATaskAddressesTheWholePool(): void
    {
        $this->command('sconcur:tasks:stop')->assertSuccessful();

        $taken = $this->channel()->takeAll(notBefore: 0);

        self::assertCount(1, $taken);
        self::assertSame(ControlActionEnum::Stop, $taken[0]->action);
        self::assertTrue($taken[0]->targetsAll());
    }

    #[Test]
    public function stopWithATaskAddressesThatTaskAlone(): void
    {
        $this->command('sconcur:tasks:stop', ['--task' => 'counting'])->assertSuccessful();

        $taken = $this->channel()->takeAll(notBefore: 0);

        self::assertCount(1, $taken);
        self::assertSame('counting', $taken[0]->target);
        self::assertFalse($taken[0]->targetsAll());
        self::assertTrue($taken[0]->targets('counting'));
        self::assertFalse($taken[0]->targets('idle'));
    }

    #[Test]
    public function restartSendsARestart(): void
    {
        $this->command('sconcur:tasks:restart', ['--task' => 'idle'])->assertSuccessful();

        $taken = $this->channel()->takeAll(notBefore: 0);

        self::assertCount(1, $taken);
        self::assertSame(ControlActionEnum::Restart, $taken[0]->action);
        self::assertSame('idle', $taken[0]->target);
    }

    /**
     * Two commands sent inside one poll window both arrive: they are appended, not
     * written over, or a script issuing one stop after another would lose the first.
     */
    #[Test]
    public function commandsFromSeparateCallsAreAllDelivered(): void
    {
        $this->command('sconcur:tasks:stop', ['--task' => 'counting'])->assertSuccessful();
        $this->command('sconcur:tasks:restart', ['--task' => 'idle'])->assertSuccessful();

        $taken = $this->channel()->takeAll(notBefore: 0);

        self::assertCount(2, $taken);
        self::assertSame(ControlCommandDto::ALL, '*');
        self::assertSame(['counting', 'idle'], array_map(
            static fn(ControlCommandDto $command): string => $command->target,
            $taken,
        ));
    }

    protected function channel(): ControlChannel
    {
        return $this->getApp()->make(ControlChannel::class);
    }
}
