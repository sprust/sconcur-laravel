<?php

declare(strict_types=1);

namespace Demo\App\Telemetry;

use GuzzleHttp\Client;
use Throwable;

/**
 * Reads the SConcur master's telemetry panel and folds its answer into the shape the
 * demo page draws.
 *
 * The panel is the master's own HTTP endpoint: every worker pushes a snapshot to the
 * collector over a unix socket, the master aggregates them, and this is where the sum
 * comes out. The demo page is served by an http worker of that same master, in the same
 * container, which is why the configured host is 127.0.0.1.
 *
 * Everything is best-effort: a master that is down, a panel that is off (port 0 or an
 * empty admin token) and a request that times out all come back as "unavailable" rather
 * than as an error page. A telemetry page that takes the application down with it would
 * be worse than one that says nothing.
 *
 * Modelled on slogger.back's dashboard client, trimmed to what one page needs.
 */
readonly class SconcurStatClient
{
    public function __construct(
        protected Client $client = new Client(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function find(): array
    {
        $url   = (string) config('sconcur.panel_host');
        $token = (string) config('sconcur.master.adminToken');

        if ($url === '' || $token === '') {
            return $this->unavailable('the panel is not configured (panel_host or adminToken is empty)');
        }

        try {
            $response = $this->client->get($url, [
                'headers'         => [
                    'Authorization' => 'Bearer ' . $token,
                    // The panel content-negotiates: without this it answers in the
                    // Prometheus text format, not JSON.
                    'Accept'        => 'application/json',
                ],
                'timeout'         => 2,
                'connect_timeout' => 1,
                'http_errors'     => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                return $this->unavailable('the panel answered ' . $response->getStatusCode());
            }

            $data = json_decode((string) $response->getBody(), true);

            if (!is_array($data)) {
                return $this->unavailable('the panel answered something that is not JSON');
            }

            return $this->map($data);
        } catch (Throwable $exception) {
            return $this->unavailable($exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function map(array $data): array
    {
        $totals = (array) ($data['totals'] ?? []);
        $master = (array) ($data['master'] ?? []);

        $groups = $this->orderByConfig(
            $this->withSilentGroups(
                array_map($this->mapGroup(...), array_values((array) ($data['groups'] ?? []))),
            ),
        );

        return [
            'available'    => true,
            'reason'       => '',
            'name'         => (string) ($data['name'] ?? ''),
            'workersTotal' => (int) ($data['workersTotal'] ?? 0),
            'workersHung'  => (int) ($data['workersHung'] ?? 0),
            'totals'       => [
                'cpuPercent'     => (float) ($totals['cpuPercent'] ?? 0),
                'memoryRssBytes' => (int) ($totals['memory']['rssBytes'] ?? 0),
                'goroutines'     => (int) ($totals['goroutines'] ?? 0),
                'work'           => $this->mapWork($totals['requests'] ?? null, $totals['consumers'] ?? null),
            ],
            'master' => [
                'cpuPercent'     => (float) ($master['cpuPercent'] ?? 0),
                'memoryRssBytes' => (int) ($master['memory']['rssBytes'] ?? 0),
            ],
            'groups'  => $groups,
            'workers' => $this->orderWorkers(
                array_map($this->mapWorker(...), array_values((array) ($data['workers'] ?? []))),
                $groups,
            ),
        ];
    }

    /**
     * Adds the configured groups the panel says nothing about, with their numbers at
     * zero.
     *
     * The panel only knows a worker that pushes telemetry to it, so a configured group
     * is missing from its answer whenever nothing of it is running. Dropping such a
     * group would read as "there is no such pool"; zeros read as "no measurements",
     * which is the truth.
     *
     * @param list<array<string, mixed>> $groups
     *
     * @return list<array<string, mixed>>
     */
    protected function withSilentGroups(array $groups): array
    {
        $reported = array_map(static fn(array $group): string => (string) $group['name'], $groups);

        foreach ($this->configuredGroupNames() as $name) {
            if (in_array($name, $reported, true)) {
                continue;
            }

            $groups[] = [
                'name'           => $name,
                'workersTotal'   => 0,
                'workersHung'    => 0,
                'cpuPercent'     => 0.0,
                'memoryRssBytes' => 0,
                'goroutines'     => 0,
                'work'           => null,
            ];
        }

        return $groups;
    }

    /**
     * Puts the groups in the order the master config declares them.
     *
     * The panel builds its answer from a map, so the pools arrive in whatever order the
     * runtime iterated them, and that order changes between calls. On a page refreshing
     * every second the rows would swap places under the cursor.
     *
     * @param list<array<string, mixed>> $groups
     *
     * @return list<array<string, mixed>>
     */
    protected function orderByConfig(array $groups): array
    {
        $order = array_flip($this->configuredGroupNames());

        usort(
            $groups,
            static fn(array $a, array $b): int => ($order[$a['name']] ?? PHP_INT_MAX)
                <=> ($order[$b['name']] ?? PHP_INT_MAX),
        );

        return $groups;
    }

    /**
     * Sorts the workers the way the groups above them are sorted, then by pid, so the
     * two tables read in the same order and a worker keeps its row between refreshes.
     *
     * @param list<array<string, mixed>> $workers
     * @param list<array<string, mixed>> $groups
     *
     * @return list<array<string, mixed>>
     */
    protected function orderWorkers(array $workers, array $groups): array
    {
        $order = array_flip(array_map(static fn(array $group): string => (string) $group['name'], $groups));

        usort(
            $workers,
            static fn(array $a, array $b): int => [
                $order[$a['group']] ?? PHP_INT_MAX,
                $a['pid'],
            ] <=> [
                $order[$b['group']] ?? PHP_INT_MAX,
                $b['pid'],
            ],
        );

        return $workers;
    }

    /**
     * The group names of the master config, in the order it declares them.
     *
     * @return list<string>
     */
    protected function configuredGroupNames(): array
    {
        $names = [];

        foreach ((array) config('sconcur.master.groups', []) as $group) {
            $name = is_array($group) ? (string) ($group['name'] ?? '') : '';

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $group
     *
     * @return array<string, mixed>
     */
    protected function mapGroup(array $group): array
    {
        $totals = (array) ($group['totals'] ?? []);

        return [
            'name'           => (string) ($group['name'] ?? ''),
            'workersTotal'   => (int) ($group['workersTotal'] ?? 0),
            'workersHung'    => (int) ($group['workersHung'] ?? 0),
            'cpuPercent'     => (float) ($totals['cpuPercent'] ?? 0),
            'memoryRssBytes' => (int) ($totals['memory']['rssBytes'] ?? 0),
            'goroutines'     => (int) ($totals['goroutines'] ?? 0),
            'work'           => $this->mapWork($totals['requests'] ?? null, $totals['consumers'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $worker
     *
     * @return array<string, mixed>
     */
    protected function mapWorker(array $worker): array
    {
        return [
            'pid'            => (int) ($worker['pid'] ?? 0),
            'group'          => (string) ($worker['group'] ?? ''),
            'hung'           => (bool) ($worker['hung'] ?? false),
            'uptimeSeconds'  => (float) ($worker['uptimeSeconds'] ?? 0),
            'cpuPercent'     => (float) ($worker['cpuPercent'] ?? 0),
            'memoryRssBytes' => (int) ($worker['memory']['rssBytes'] ?? 0),
            'goroutines'     => (int) ($worker['goroutines'] ?? 0),
            'work'           => $this->mapWork($worker['requests'] ?? null, $worker['consumers'] ?? null),
        ];
    }

    /**
     * Folds the panel's two workload sections into the one the page shows. Which of them
     * a pool sends depends on what it runs, and no pool sends both — but the master's
     * totals do, being the sum of unlike pools, so both are read here rather than one or
     * the other.
     *
     * Null when neither section is there: a pool that counts nothing is not a pool that
     * counted zero.
     *
     * @return array<string, mixed>|null
     */
    protected function mapWork(mixed $requests, mixed $consumers): ?array
    {
        $requests  = is_array($requests) ? $requests : null;
        $consumers = is_array($consumers) ? $consumers : null;

        if ($requests === null && $consumers === null) {
            return null;
        }

        $completed = (int) ($requests['completed'] ?? 0);
        $acked     = (int) ($consumers['acked'] ?? 0);
        $refused   = (int) ($consumers['refused'] ?? 0);
        $timed     = (int) ($consumers['timed'] ?? 0);

        // What each side's average is a mean over — `completed` for requests, `timed`
        // for consumers — which is what the two have to be weighted by to be averaged
        // together.
        $measured = $completed + $timed;

        $totalMs = $completed * (float) ($requests['avgMs'] ?? 0)
            + $timed * (float) ($consumers['avgMs'] ?? 0);

        return [
            'inProcess'        => (int) ($requests['inFlight'] ?? 0) + (int) ($consumers['inFlight'] ?? 0),
            'inProcess1to5s'   => (int) ($requests['inFlight1to5s'] ?? 0) + (int) ($consumers['inFlight1to5s'] ?? 0),
            'inProcess5to15s'  => (int) ($requests['inFlight5to15s'] ?? 0) + (int) ($consumers['inFlight5to15s'] ?? 0),
            'inProcessOver15s' => (int) ($requests['inFlightOver15s'] ?? 0) + (int) ($consumers['inFlightOver15s'] ?? 0),
            // A request that ended with a 500 is still counted by `completed`, so the
            // counterpart on the consumer side is everything that settled, refused
            // deliveries included — not `acked` alone.
            'finished'         => $completed + $acked + $refused,
            'refused'          => $refused,
            'measured'         => $measured,
            'avgMs'            => $measured > 0 ? $totalMs / $measured : 0.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function unavailable(string $reason): array
    {
        return [
            'available'    => false,
            'reason'       => $reason,
            'name'         => '',
            'workersTotal' => 0,
            'workersHung'  => 0,
            'totals'       => [
                'cpuPercent'     => 0.0,
                'memoryRssBytes' => 0,
                'goroutines'     => 0,
                'work'           => null,
            ],
            'master' => [
                'cpuPercent'     => 0.0,
                'memoryRssBytes' => 0,
            ],
            'groups'  => [],
            'workers' => [],
        ];
    }
}
