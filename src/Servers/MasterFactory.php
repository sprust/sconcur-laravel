<?php

declare(strict_types=1);

namespace SConcur\Laravel\Servers;

use Closure;
use RuntimeException;
use SConcur\Worker\AccessLogTarget;
use SConcur\Worker\LogTarget;
use SConcur\Worker\RestartPolicy;
use SConcur\Worker\WatchdogEvent;
use SConcur\Worker\WorkerGroupConfig;
use SConcur\Worker\WorkerMaster;

/**
 * Builds the library's WorkerMaster out of config('sconcur.master').
 *
 * The top level of that array is the master's own settings, and beside them the settings
 * every group falls back on — restart policy, timeouts, backoff, the watchdog, readiness,
 * access lines — which a group may set for itself. `env` is the one that adds up rather
 * than falls back: a group's variables go on top of the master-wide ones. A group's
 * `role` says which of the package's runtimes its workers run (WorkerRoles).
 */
readonly class MasterFactory
{
    public function __construct(
        protected WorkerRoles $workerRoles,
    ) {
    }

    /**
     * @param array<string, mixed>              $config          config('sconcur.master')
     * @param null|Closure(WatchdogEvent): void $onWatchdogEvent called in the master when its
     *                                                           watchdog acts on a worker
     */
    public function make(array $config, ?Closure $onWatchdogEvent = null): WorkerMaster
    {
        $groups = [];

        foreach ((array) ($config['groups'] ?? []) as $group) {
            $groups[] = $this->group(
                group: (array) $group,
                defaults: $config,
            );
        }

        $logDir = (string) ($config['logDir'] ?? '');

        return new WorkerMaster(
            runtimeDir: (string) ($config['runtimeDir'] ?? ''),
            groups: $groups,
            logDir: $logDir !== '' ? $logDir : null,
            name: (string) ($config['name'] ?? WorkerMaster::DEFAULT_NAME),
            rotateDays: (int) ($config['rotateDays'] ?? 3),
            logTo: LogTarget::from((string) ($config['logTo'] ?? LogTarget::File->value)),
            panelPort: (int) ($config['panelPort'] ?? 0),
            adminToken: (string) ($config['adminToken'] ?? ''),
            onWatchdogEvent: $onWatchdogEvent,
        );
    }

    /**
     * @param array<array-key, mixed> $group    the group's own keys
     * @param array<array-key, mixed> $defaults the master-wide keys it falls back on
     */
    protected function group(array $group, array $defaults): WorkerGroupConfig
    {
        $setting = static fn(string $key, mixed $default): mixed => $group[$key] ?? $defaults[$key] ?? $default;

        $name = (string) ($group['name'] ?? '');
        $role = WorkerRoleEnum::tryFrom((string) ($group['role'] ?? ''));

        if ($role === null) {
            throw new RuntimeException(
                sprintf(
                    'sconcur: group "%s" has no known role; expected one of: %s',
                    $name,
                    implode(', ', array_column(WorkerRoleEnum::cases(), 'value')),
                ),
            );
        }

        /** @var array<string, mixed> $group */
        return new WorkerGroupConfig(
            name: $name,
            role: $this->workerRoles->for($role, $group),
            workerCount: (int) ($group['workerCount'] ?? 0),
            restartPolicy: RestartPolicy::from((string) $setting('restartPolicy', RestartPolicy::Always->value)),
            shutdownTimeoutMs: (int) $setting('shutdownTimeoutMs', 10_000),
            restartBackoffMs: (int) $setting('restartBackoffMs', 200),
            maxRestartBackoffMs: (int) $setting('maxRestartBackoffMs', 30_000),
            watchdogTimeoutMs: (int) $setting('watchdogTimeoutMs', 60_000),
            readyTimeoutMs: (int) $setting('readyTimeoutMs', 30_000),
            accessLog: AccessLogTarget::from((string) $setting('accessLog', AccessLogTarget::File->value)),
            env: array_map(
                strval(...),
                [
                    ...(array) ($defaults['env'] ?? []),
                    ...(array) ($group['env'] ?? []),
                ],
            ),
        );
    }
}
