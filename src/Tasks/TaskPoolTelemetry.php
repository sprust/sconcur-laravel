<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tasks;

/**
 * Pushes the pool's snapshots to the master's telemetry collector.
 *
 * TODO: temporary, and deliberately so. Every other worker of the master reports from
 * the Go side of its runtime (ext/internal/stats), which samples and sends without the
 * PHP thread doing anything; this pool runs no such runtime, so until the Go side learns
 * to report for a plain worker, the sampling and the framing are done here by hand. When
 * that lands, drop this class and the sender it starts — the wire format below is the
 * contract it will speak, so nothing else has to change.
 *
 * The library's own SocketClient would be the natural way to write this, and it is not
 * usable here: it dials "host:port" and its v1 says so in as many words — "unix-сокеты
 * (только TCP)" — while the collector listens on a unix socket. So the frame goes out
 * over a plain non-blocking PHP stream. Nothing is lost by it at this size: one sub-kilobyte
 * write a second never has the thread to itself for long enough to matter. If the PHP
 * path ever should go through the library instead, unix-socket support in SocketClient
 * is the prerequisite.
 *
 * The channel is an open contract (docs/admin-stats.ru.md, "Контракт push-протокола"):
 * a unix stream socket, a 4-byte big-endian length prefix, and a UTF-8 JSON body
 * `{"t":"snapshot","s":<snapshot>}`. Best-effort and at-most-once — no acks, and a
 * dropped frame costs one second of freshness, nothing more. The master takes a closed
 * connection to mean the worker left, so the socket is held open for the pool's life.
 *
 * What a PHP-side sampler can and cannot know: RSS and CPU come from /proc, which is the
 * same source the Go side reads. Go-runtime memory and the goroutine count do not exist
 * for this worker and go out as zero. None of the three workload sections is sent —
 * requests belong to a server and connections to a socket server. The third,
 * `consumers`, is sent: a tick is to a task what a delivery is to a consumer, so the
 * pool's counters fit it exactly and the panel's own columns fill in unchanged
 * (TaskPoolMetrics).
 */
class TaskPoolTelemetry
{
    /** The master flags a worker hung after 15 s without a snapshot; report well inside that. */
    protected const int INTERVAL_MS = 1000;

    /** The kernel clock tick, read once per process; see clockTicksPerSecond(). */
    protected static ?float $clockTicks = null;

    /** @var resource|null */
    protected mixed $socket = null;

    protected float $startedAt;

    protected float $dueAt = 0;

    /** Previous /proc/self/stat sample: [cpu seconds, wall clock], for the CPU delta. */
    protected ?array $previousCpu = null;

    public function __construct(
        protected string $socketPath,
        protected string $name,
        protected ?TaskPoolMetrics $metrics = null,
    ) {
        $this->startedAt = microtime(true);
    }

    /**
     * Builds a sender from the environment the master injects into every worker it
     * spawns. Absent env means nobody is collecting — a standalone run — and telemetry
     * stays off.
     */
    public static function fromEnvironment(?TaskPoolMetrics $metrics = null): ?self
    {
        $socket = (string) getenv('SCONCUR_TELEMETRY_SOCKET');
        $name   = (string) getenv('SCONCUR_SERVER_NAME');

        if ($socket === '' || $name === '') {
            return null;
        }

        return new self($socket, $name, $metrics);
    }

    /**
     * Sends a snapshot if one is due. Called from the controller's tick, which runs far
     * more often than the reporting interval.
     */
    public function push(): void
    {
        $now = microtime(true);

        if ($now < $this->dueAt) {
            return;
        }

        $this->dueAt = $now + self::INTERVAL_MS / 1000;

        $handle = $this->connection();

        if ($handle === null) {
            return;
        }

        $body = json_encode(['t' => 'snapshot', 's' => $this->snapshot($now)]);

        if ($body === false) {
            return;
        }

        $frame = pack('N', strlen($body)) . $body;

        // Non-blocking on purpose: a collector that stopped reading must slow the pool
        // down by nothing at all. But a short write cannot be shrugged off as a dropped
        // snapshot — the stream is length-prefixed, so half a frame left in the socket
        // makes the collector read the next frame's header as the tail of this body and
        // mis-frame everything after it. Depending on what the bytes decode to, the pool
        // either disappears from the panel for the life of the process or the collector
        // drops the connection on an absurd length. Dropping it here is the way back:
        // the next push reconnects, and only this snapshot is lost.
        //
        // Compared against the length rather than `=== false` because a non-blocking
        // write reports the bytes it managed, and a partial one is a number, not false.
        if (@fwrite($handle, $frame) !== strlen($frame)) {
            $this->close();
        }
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);

            $this->socket = null;
        }
    }

    /** @return resource|null */
    protected function connection(): mixed
    {
        if ($this->socket !== null) {
            return $this->socket;
        }

        $socket = @stream_socket_client(
            'unix://' . $this->socketPath,
            $errorCode,
            $errorMessage,
            1.0,
            STREAM_CLIENT_CONNECT,
        );

        if ($socket === false) {
            return null;
        }

        stream_set_blocking($socket, false);

        return $this->socket = $socket;
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshot(float $now): array
    {
        $rssBytes = $this->rssBytes();

        return [
            'name'          => $this->name,
            'pid'           => getmypid(),
            'updatedAtMs'   => (int) round($now * 1000),
            'startedAtMs'   => (int) round($this->startedAt * 1000),
            'uptimeSeconds' => round($now - $this->startedAt, 3),
            'memory'        => [
                'rssBytes' => $rssBytes,
                // No Go runtime of our own to account for, so the whole of RSS is the
                // PHP side. Reported rather than omitted: the panel subtracts one from
                // the other and would otherwise show the split as unknown.
                'goRuntimeBytes'    => 0,
                'nonExtensionBytes' => $rssBytes,
            ],
            'cpuPercent' => $this->cpuPercent($now),
            'goroutines' => 0,
            // The pool's ticks, in the section the panel already renders as
            // "In-flight / Handled / Refused". Omitted when the pool is configured not
            // to report them, and a section nobody sends is left out of the answer.
            ...($this->metrics === null ? [] : ['consumers' => $this->metrics->section()]),
        ];
    }

    /** RSS of the whole process, the same field the Go sampler reads. */
    protected function rssBytes(): int
    {
        $status = @file_get_contents('/proc/self/status');

        if ($status === false || preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $status, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1] * 1024;
    }

    /**
     * CPU used since the previous sample, as a percentage of one core — the same shape
     * as the Go sampler's, and like it, zero on the first sample because a rate needs
     * two points.
     */
    protected function cpuPercent(float $now): float
    {
        $ticks = $this->cpuSeconds();

        if ($ticks === null) {
            return 0;
        }

        $previous          = $this->previousCpu;
        $this->previousCpu = [$ticks, $now];

        if ($previous === null) {
            return 0;
        }

        [$previousTicks, $previousAt] = $previous;

        $elapsed = $now - $previousAt;

        if ($elapsed <= 0) {
            return 0;
        }

        return round(($ticks - $previousTicks) / $elapsed * 100, 2);
    }

    /** User + system time of this process, in seconds. */
    protected function cpuSeconds(): ?float
    {
        $stat = @file_get_contents('/proc/self/stat');

        if ($stat === false) {
            return null;
        }

        // The comm field may itself contain spaces and parentheses, so the fields are
        // counted from after the closing one rather than by splitting the whole line.
        $tail = strrchr($stat, ')');

        if ($tail === false) {
            return null;
        }

        $fields = preg_split('/\s+/', trim(substr($tail, 1)));

        if ($fields === false || !isset($fields[11], $fields[12])) {
            return null;
        }

        $hertz = $this->clockTicksPerSecond();

        return ((float) $fields[11] + (float) $fields[12]) / $hertz;
    }

    /**
     * The kernel's clock tick, asked for once.
     *
     * It cannot change while the process runs, and reading it costs a fork and an exec —
     * which on the one-second reporting interval would be some eighty thousand of them a
     * day, inside a runtime whose whole point is not to block. 100 is the Linux default
     * and the fallback for a host where shell_exec is disabled.
     */
    protected function clockTicksPerSecond(): float
    {
        if (self::$clockTicks !== null) {
            return self::$clockTicks;
        }

        $hertz = (float) (@shell_exec('getconf CLK_TCK') ?: 100);

        return self::$clockTicks = $hertz > 0 ? $hertz : 100.0;
    }
}
