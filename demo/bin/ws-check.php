<?php

declare(strict_types=1);

/**
 * Checks the demo's WebSocket pool from the outside, the way a browser uses it.
 *
 * The page is the usual way to look at the pool, but it needs a browser and it says
 * nothing an exit code can be read from. This walks the same path — handshake, ping,
 * subscribe, publish, delivery — and reports each step, so a change to the pool can be
 * checked without opening anything.
 *
 * Run it from the workers container, where the extension lives:
 *
 *     make ws-check
 *     make ws-check c=50
 *
 * The argument is how many messages to publish in the burst; the checker then insists on
 * receiving exactly those, numbered from one with nothing missing.
 */

use Illuminate\Contracts\Console\Kernel;
use SConcur\Features\WsClient\WsClient;
use SConcur\Features\WsClient\WsClientOptions;

require __DIR__ . '/../../vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require __DIR__ . '/../bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

$count   = max(1, (int) ($argv[1] ?? 5));
$host    = getenv('WS_CHECK_HOST') ?: 'scl-nginx:80';
$channel = 'demo';

$key    = (string) config('sconcur.ws.app_key');
$prefix = (string) config('sconcur.ws.path_prefix', '/app');

$failures = 0;

$step = static function (string $title, bool $ok, string $detail = '') use (&$failures): void {
    if (!$ok) {
        $failures++;
    }

    echo ($ok ? '  ok  ' : ' FAIL '), str_pad($title, 34), $detail, "\n";
};

echo "ws check against $host, channel $channel, burst of $count\n\n";

if ($key === '') {
    $step('the app key is configured', false, 'SCONCUR_WS_APP_KEY is empty');

    exit(1);
}

// readTimeoutMs is what turns a pool that never answers into a failed check rather than
// a script that hangs: every read below is bounded by it.
$client = new WsClient(new WsClientOptions(readTimeoutMs: 8_000));

$url = "ws://$host$prefix/$key?protocol=7&client=php&version=1.0";

try {
    $connection = $client->connect($url);
} catch (Throwable $exception) {
    // The upgrade is refused before PHP sees anything when the pool is down or the key in
    // the path is not the configured one — a 404 on the handshake. Reported as a failed
    // step rather than as a stack trace: the message is the answer.
    $step('handshake', false, $exception->getMessage());

    echo "\n1 check(s) failed\n";

    exit(1);
}

$established = json_decode((string) $connection->read(), true);

$socketId = (string) (json_decode((string) ($established['data'] ?? '{}'), true)['socket_id'] ?? '');

$step(
    'handshake',
    ($established['event'] ?? '') === 'pusher:connection_established' && $socketId !== '',
    "socket_id=$socketId",
);

// The socket id is the ws worker's pid and a counter, so it also names the worker this
// connection landed on — the number to compare with the publisher's pid below.
$step('the socket id names a worker', str_contains($socketId, '.'), 'ws worker pid=' . strtok($socketId, '.'));

$connection->write((string) json_encode(['event' => 'pusher:ping', 'data' => []]));

$step('ping', (json_decode((string) $connection->read(), true)['event'] ?? '') === 'pusher:pong');

$connection->write((string) json_encode(['event' => 'pusher:subscribe', 'data' => ['channel' => $channel]]));

$step(
    'subscribe',
    (json_decode((string) $connection->read(), true)['event'] ?? '') === 'pusher_internal:subscription_succeeded',
);

// Published over HTTP, so the message crosses a process boundary: the http worker that
// answers this call and the ws worker holding the socket are different processes, and the
// bus is the only thing between them.
$curl = curl_init("http://$host/api/ws/broadcast");

curl_setopt_array($curl, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => (string) json_encode(['text' => 'ws-check', 'count' => $count]),
]);

$published = json_decode((string) curl_exec($curl), true);

curl_close($curl);

$step(
    'publish over http',
    ($published['published'] ?? 0) === $count,
    'http worker pid=' . ($published['workerPid'] ?? '?'),
);

$numbers = [];

for ($read = 0; $read < $count; $read++) {
    $frame = json_decode((string) $connection->read(), true);

    if (($frame['event'] ?? '') !== 'demo.message') {
        continue;
    }

    $numbers[] = (int) strtok((string) (json_decode((string) $frame['data'], true)['text'] ?? ''), ' ');
}

sort($numbers);

$step(
    'the whole burst arrives',
    $numbers === range(1, $count),
    count($numbers) . " of $count, "
        . (count(array_unique($numbers)) === count($numbers) ? 'no duplicates' : 'DUPLICATES'),
);

$connection->close();

echo "\n", $failures === 0 ? "all checks passed\n" : "$failures check(s) failed\n";

exit($failures === 0 ? 0 : 1);
