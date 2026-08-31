<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Console;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Worker\MasterConfig;

/**
 * Reload is the one command that needs a file: the master re-reads the config in its own
 * process, so an in-memory object cannot reach it. What it writes has to be the same
 * array the other commands supervise with, or the config the master rolls onto and the
 * config it was started with drift apart.
 */
class MasterConfigFileTest extends BaseTestCase
{
    #[Test]
    public function reloadWritesTheConfigTheOtherCommandsUse(): void
    {
        $config = (array) config('sconcur.master');

        $path = $config['runtimeDir'] . '/' . $config['name'] . '.config.json';

        @unlink($path);

        // Not running, so it stops there — but only after the file has been written,
        // which is what this is about.
        $this->command('sconcur:servers:master:reload')->run();

        self::assertFileExists($path);

        $written = json_decode((string) file_get_contents($path), true);

        self::assertSame($config, $written);

        // And what was written is a config the library accepts.
        self::assertSame($config['name'], MasterConfig::fromArray($written)->name());

        @unlink($path);
    }

    /**
     * The package does not merge its config, so an unpublished one is empty rather than
     * defaulted. What comes out names the fix — "publish the config" — instead of the
     * library's "groups must be a non-empty list", which reads like a broken config
     * rather than a missing one.
     *
     * It arrives as an exception rather than as a failed exit code, which is what the
     * command actually does today. A message the reader can act on is the point either
     * way; if that ever becomes a plain error line and a non-zero exit, this is the test
     * that says so.
     */
    #[Test]
    public function anEmptyConfigIsReportedAsMissingRatherThanBroken(): void
    {
        config()->set('sconcur.master', []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/vendor:publish/');

        $this->command('sconcur:servers:master:status')->run();
    }
}
