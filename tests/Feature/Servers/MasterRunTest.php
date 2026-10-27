<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Servers;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Worker\MasterControl;
use Workbench\App\Listeners\RecordWorkerForked;

/**
 * The master as an application runs it: `sconcur:servers:master:start` in a process of its
 * own, the workbench application booted in it, and every worker a fork of that process.
 *
 * What is held here is what no unit can show: that a booted application leaves a process
 * the library agrees to fork, that the forks serve, that every one of them raises
 * WorkerForked, and that status and stop reach the master from this process.
 */
class MasterRunTest extends BaseTestCase
{
    protected const int START_TIMEOUT_MS = 15_000;

    /** @var null|resource */
    private $process = null;

    private string $output;

    protected function setUp(): void
    {
        parent::setUp();

        @unlink(RecordWorkerForked::path());

        $this->output = (string) tempnam(sys_get_temp_dir(), 'master-run-');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            $this->masterControl()->stop();

            proc_terminate($this->process);
            proc_close($this->process);
        }

        @unlink(RecordWorkerForked::path());
        @unlink($this->output);

        parent::tearDown();
    }

    #[Test]
    public function theMasterForksWorkersThatServeAndStopsOnRequest(): void
    {
        $this->startMaster();

        // Any answer will do, a 404 included: the testbench CLI loads no workbench routes,
        // and what is under test is that a forked worker gets a request to Laravel.
        $this->waitFor(
            condition: fn(): bool => $this->httpStatusCode() > 0,
            what: 'the http workers to answer',
        );

        $this->waitFor(
            condition: fn(): bool => count($this->forkedWorkers()) === 3,
            what: 'every worker to raise WorkerForked',
        );

        $forkedWorkers = $this->forkedWorkers();

        ksort($forkedWorkers);

        // The master-wide `env` reaches every worker, and a group's own value wins in it —
        // both already set when WorkerForked is raised.
        self::assertSame(
            [
                'http#0'  => 'master',
                'http#1'  => 'master',
                'tasks#0' => 'tasks',
            ],
            $forkedWorkers,
        );

        $this->command('sconcur:servers:master:status')
            ->expectsOutputToContain('running: pid=')
            ->expectsOutputToContain('http: workers=2')
            ->assertSuccessful();

        $this->command('sconcur:servers:master:stop')
            ->expectsOutputToContain('stopped')
            ->assertSuccessful();

        self::assertFalse($this->masterControl()->isRunning());
        self::assertSame(0, $this->exitCode(), (string) file_get_contents($this->output));
    }

    #[Test]
    public function aMasterThatDoesNotRunIsReportedAsStopped(): void
    {
        $this->command('sconcur:servers:master:status')
            ->expectsOutputToContain('stopped')
            ->assertExitCode(3);

        $this->command('sconcur:servers:master:stop')
            ->expectsOutputToContain('not running')
            ->assertSuccessful();
    }

    /**
     * The package does not merge its config, so an unpublished one is empty rather than
     * defaulted. What comes out names the fix — "publish the config" — instead of the
     * library's "needs at least one group", which reads like a broken config rather than
     * a missing one.
     */
    #[Test]
    public function anEmptyConfigIsReportedAsMissingRatherThanBroken(): void
    {
        config()->set('sconcur.master', []);

        $this->expectExceptionMessageMatches('/vendor:publish/');

        $this->command('sconcur:servers:master:status')->run();
    }

    private function startMaster(): void
    {
        // The container's own CACHE_STORE names a store the workbench does not define;
        // phpunit.xml sets `array` for this process only, so the child is told the same.
        $environment = [
            ...getenv(),
            'CACHE_STORE' => 'array',
        ];

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/vendor/bin/testbench', 'sconcur:servers:master:start'],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $this->output, 'a'],
                2 => ['file', $this->output, 'a'],
            ],
            $pipes,
            null,
            $environment,
        );

        self::assertIsResource($process);

        $this->process = $process;
    }

    private function exitCode(): int
    {
        $process = $this->process;

        self::assertIsResource($process);

        // proc_get_status() reports the exit code once, to the first call after the exit;
        // every call after that says -1. So it is kept from the call that saw the exit.
        $exitCode = -1;

        $this->waitFor(
            condition: static function () use ($process, &$exitCode): bool {
                $status = proc_get_status($process);

                if ($status['running']) {
                    return false;
                }

                $exitCode = $status['exitcode'];

                return true;
            },
            what: 'the master process to exit',
        );

        proc_close($process);

        $this->process = null;

        return $exitCode;
    }

    private function httpStatusCode(): int
    {
        $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);

        $body = @file_get_contents('http://127.0.0.1:28090/', false, $context);

        if ($body === false || !isset($http_response_header[0])) {
            return 0;
        }

        return (int) explode(' ', $http_response_header[0])[1];
    }

    /**
     * Every worker that raised WorkerForked, `group#slot` => the WORKBENCH_WORKER_ENV it had.
     *
     * @return array<string, string>
     */
    private function forkedWorkers(): array
    {
        $lines = @file(RecordWorkerForked::path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $forkedWorkers = [];

        foreach ($lines === false ? [] : $lines as $line) {
            $fields = explode(' ', $line);

            $forkedWorkers[$fields[0]] = substr((string) end($fields), strlen('env='));
        }

        return $forkedWorkers;
    }

    /**
     * @param callable(): bool $condition
     */
    private function waitFor(callable $condition, string $what): void
    {
        $deadline = microtime(true) + self::START_TIMEOUT_MS / 1000;

        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }

            usleep(100_000);
        }

        self::fail('timed out waiting for ' . $what . ":\n" . file_get_contents($this->output));
    }

    private function masterControl(): MasterControl
    {
        return new MasterControl(
            runtimeDir: (string) config('sconcur.master.runtimeDir'),
            name: (string) config('sconcur.master.name'),
        );
    }
}
