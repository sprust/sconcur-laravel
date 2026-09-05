<?php

declare(strict_types=1);

namespace Demo\App\Support;

/**
 * How many workers and coroutines the pools run with, as the demo page can change them.
 *
 * The numbers live in a file rather than in ENV because they have to be readable by a
 * process that did not exist when they were set: `demo/config/sconcur.php` reads them
 * while it builds the master config, and the process doing that is a fresh artisan the
 * master's reload spawns. A file is what both ends can see.
 *
 * Everything is clamped on the way in and on the way out. The file is written by an HTTP
 * request and read by the master's own config — a hand-edited number that makes the
 * config invalid would leave the master unable to start, which is not a state a demo
 * should be able to talk itself into.
 */
class ScalingSettings
{
    public const int HTTP_WORKERS_MIN = 1;

    public const int HTTP_WORKERS_MAX = 8;

    /** Zero is allowed and meaningful: below one the group leaves the master config. */
    public const int RABBITMQ_WORKERS_MIN = 0;

    public const int RABBITMQ_WORKERS_MAX = 4;

    public const int RABBITMQ_COROUTINES_MIN = 1;

    public const int RABBITMQ_COROUTINES_MAX = 32;

    /** Zero here means the same as it does above: no ws group in the master config. */
    public const int WS_WORKERS_MIN = 0;

    public const int WS_WORKERS_MAX = 4;

    /**
     * The values in force, defaults filled in from ENV.
     *
     * @return array{httpWorkers: int, rabbitmqWorkers: int, rabbitmqCoroutines: int, wsWorkers: int}
     */
    public static function current(): array
    {
        return self::clamp(self::read());
    }

    /**
     * Writes the values and returns what was actually stored after clamping.
     *
     * @param array<string, mixed> $values
     *
     * @return array{httpWorkers: int, rabbitmqWorkers: int, rabbitmqCoroutines: int, wsWorkers: int}
     */
    public static function store(array $values): array
    {
        $stored = self::clamp($values + self::read());

        $path = self::path();

        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        file_put_contents(
            $path,
            json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        );

        return $stored;
    }

    /**
     * @return array{
     *     httpWorkers: array{min: int, max: int},
     *     rabbitmqWorkers: array{min: int, max: int},
     *     rabbitmqCoroutines: array{min: int, max: int},
     *     wsWorkers: array{min: int, max: int}
     * }
     */
    public static function limits(): array
    {
        return [
            'httpWorkers'        => ['min' => self::HTTP_WORKERS_MIN, 'max' => self::HTTP_WORKERS_MAX],
            'rabbitmqWorkers'    => ['min' => self::RABBITMQ_WORKERS_MIN, 'max' => self::RABBITMQ_WORKERS_MAX],
            'rabbitmqCoroutines' => ['min' => self::RABBITMQ_COROUTINES_MIN, 'max' => self::RABBITMQ_COROUTINES_MAX],
            'wsWorkers'          => ['min' => self::WS_WORKERS_MIN, 'max' => self::WS_WORKERS_MAX],
        ];
    }

    public static function path(): string
    {
        return storage_path('app/scaling.json');
    }

    /**
     * Asks for the named groups to be rolled onto the stored numbers.
     *
     * A request rather than the reload itself, because reload waits for the roll to
     * finish and the caller is an HTTP worker the roll is going to take down. The
     * server drains gracefully, so an ordinary request would survive — but this one
     * would still be inside reload() when its own turn came, and then the server is
     * waiting for the handler while the handler waits for the master. That standoff
     * ends at shutdownTimeoutMs with the worker killed and the request cut. The task
     * pool applies it instead: its group is never one of the rolled ones.
     *
     * @param list<string> $groups
     */
    public static function requestReload(array $groups): void
    {
        if ($groups === []) {
            return;
        }

        file_put_contents(
            self::requestPath(),
            json_encode(['groups' => $groups, 'at' => microtime(true)]) . PHP_EOL,
        );
    }

    /**
     * Takes the pending request and clears it, so one request rolls the groups once.
     *
     * @return list<string>
     */
    public static function takeReloadRequest(): array
    {
        $path = self::requestPath();

        if (!is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        unlink($path);

        $groups = is_array($data) ? ($data['groups'] ?? []) : [];

        return is_array($groups) ? array_values(array_map(strval(...), $groups)) : [];
    }

    public static function requestPath(): string
    {
        return storage_path('app/scaling-request.json');
    }

    /**
     * What is on disk, or the ENV defaults when nothing has been set yet.
     *
     * @return array<string, mixed>
     */
    protected static function read(): array
    {
        $defaults = [
            'httpWorkers'        => (int) env('SCONCUR_HTTP_WORKER_COUNT', 2),
            'rabbitmqWorkers'    => (int) env('SCONCUR_RABBITMQ_WORKER_COUNT', 1),
            'rabbitmqCoroutines' => (int) env('SCONCUR_RABBITMQ_QUEUE_CONSUMERS', 4),
            'wsWorkers'          => (int) env('SCONCUR_WS_WORKER_COUNT', 1),
        ];

        $path = self::path();

        if (!is_file($path)) {
            return $defaults;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data + $defaults : $defaults;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array{httpWorkers: int, rabbitmqWorkers: int, rabbitmqCoroutines: int, wsWorkers: int}
     */
    protected static function clamp(array $values): array
    {
        return [
            'httpWorkers' => min(
                max((int) ($values['httpWorkers'] ?? self::HTTP_WORKERS_MIN), self::HTTP_WORKERS_MIN),
                self::HTTP_WORKERS_MAX,
            ),
            'rabbitmqWorkers' => min(
                max((int) ($values['rabbitmqWorkers'] ?? self::RABBITMQ_WORKERS_MIN), self::RABBITMQ_WORKERS_MIN),
                self::RABBITMQ_WORKERS_MAX,
            ),
            'rabbitmqCoroutines' => min(
                max(
                    (int) ($values['rabbitmqCoroutines'] ?? self::RABBITMQ_COROUTINES_MIN),
                    self::RABBITMQ_COROUTINES_MIN,
                ),
                self::RABBITMQ_COROUTINES_MAX,
            ),
            'wsWorkers' => min(
                max((int) ($values['wsWorkers'] ?? self::WS_WORKERS_MIN), self::WS_WORKERS_MIN),
                self::WS_WORKERS_MAX,
            ),
        ];
    }
}
