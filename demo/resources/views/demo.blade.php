<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SConcur Laravel Demo</title>
    <style>
        :root {
            --bg: #f6f7f9;
            --panel: #ffffff;
            --border: #dfe3e8;
            --text: #1c2024;
            --muted: #6b7280;
            --accent: #2f6feb;
            --button: #1a7f37;
            --button-border: #176c2f;
            --ok: #1a7f37;
            --warn: #9a6700;
            --bad: #b42318;
            --mono: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #14171a;
                --panel: #1c2024;
                --border: #2c3138;
                --text: #e6e8ea;
                --muted: #9aa3ad;
                --accent: #6ea1ff;
                --button: #2f8f4a;
                --button-border: #3aa259;
                --ok: #4ac26b;
                --warn: #d4a72c;
                --bad: #ff7b72;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 24px;
            background: var(--bg);
            color: var(--text);
            font: 14px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }

        h1 { font-size: 20px; margin: 0 0 4px; }
        h2 { font-size: 15px; margin: 0 0 12px; }

        .wrap { max-width: 1180px; margin: 0 auto; }

        .sub { color: var(--muted); margin: 0 0 24px; }
        .sub code { font-family: var(--mono); color: var(--text); }

        .panel {
            display: flex;
            flex-direction: column;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 16px;
        }

        /* Pushes the answer block to the bottom, so panels with two controls and panels
           with none still line their blocks up across a row. */
        .panel > pre { margin-top: auto; }

        .grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }

        .tiles { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); }

        .tile { border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; }
        .tile .k { color: var(--muted); font-size: 12px; }
        .tile .v { font-family: var(--mono); font-size: 18px; }

        table { width: 100%; border-collapse: collapse; font-family: var(--mono); font-size: 12.5px; }
        th, td { text-align: right; padding: 6px 8px; border-bottom: 1px solid var(--border); white-space: nowrap; }
        th:first-child, td:first-child { text-align: left; }
        th { color: var(--muted); font-weight: 500; }
        .scroll { overflow-x: auto; }

        input {
            width: 100%; padding: 5px 7px; font-family: var(--mono); font-size: 13px;
            border: 1px solid var(--border); border-radius: 6px;
            background: var(--bg); color: var(--text);
        }

        button {
            padding: 6px 14px; font-size: 13px; cursor: pointer;
            border: 1px solid var(--button-border); border-radius: 6px;
            background: var(--button); color: #fff;
        }

        button:hover:not(:disabled) { filter: brightness(1.12); }

        button:disabled { opacity: .55; cursor: progress; }

        /* One parameter per row — label in a fixed column, field filling the rest — and
           the button of that group under them, right-aligned with the fields. A panel is
           one or more such groups; a flex row of inputs and buttons mixed together put
           the labels at different places in every panel and read as a jumble. */
        .controls { margin-bottom: 12px; }

        .controls + .controls { margin-top: -4px; }

        .field { display: grid; grid-template-columns: 76px 1fr; gap: 8px; align-items: center; margin-bottom: 8px; }

        .field > label { color: var(--muted); font-size: 12px; text-align: right; }

        .actions { display: flex; justify-content: flex-end; }

        /* Two numbers that belong to one pool, read as one control: the processes and
           the consumer coroutines each of them opens. Apart they read as unrelated
           settings, and "coroutines" on its own says nothing about whose they are. */
        .pair { display: flex; }
        .pair > input:first-child { border-top-right-radius: 0; border-bottom-right-radius: 0; }
        .pair > input:last-child { border-top-left-radius: 0; border-bottom-left-radius: 0; margin-left: -1px; }
        .pair > input:focus { position: relative; z-index: 1; }

        .hint { color: var(--muted); font-size: 11px; }

        /* Fixed height, not max-height: the answer block goes from one em-dash to a
           screenful and back on every run, and a block that grows with its content moves
           the panel, the grid row and everything under it while the reader is looking at
           it. It scrolls inside instead. */
        pre {
            margin: 0; padding: 10px; border-radius: 8px; height: 220px; overflow: auto;
            background: var(--bg); border: 1px solid var(--border);
            font-family: var(--mono); font-size: 12px;
        }

        /* A checkbox is not a text field: the rule above stretches every input to the
           column, which on a box means a wide grey rectangle with a tick in the corner. */
        input[type="checkbox"] { width: auto; justify-self: start; margin: 0; }

        /* The connection state, next to the heading it belongs to. A ws panel without one
           reads the same whether the socket is open or gone, and the log below it only
           says what happened, not what is true now. */
        .badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 2px 8px; border-radius: 999px;
            border: 1px solid var(--border); background: var(--bg);
            font-family: var(--mono); font-size: 11px; font-weight: normal;
        }

        .badge::before {
            content: ''; width: 7px; height: 7px; border-radius: 50%;
            background: currentColor;
        }

        .ok { color: var(--ok); }
        .warn { color: var(--warn); }
        .bad { color: var(--bad); }
        .muted { color: var(--muted); }
    </style>
</head>
<body>
<div class="wrap">
    <h1>SConcur Laravel Demo</h1>
    <p class="sub">
        Served by the SConcur HTTP pool — this page is rendered inside worker
        <code>{{ $workerPid }}</code>.
        DB connection <code>{{ $connection }}</code>, queue <code>{{ $queue }}</code>.
    </p>

    <div class="panel">
        <h2>Master telemetry <span class="muted" id="telemetry-state"></span></h2>
        <div class="tiles" id="telemetry-tiles"></div>
        <h2 style="margin:16px 0 8px">Groups</h2>
        <div class="scroll"><table id="groups-table"></table></div>
        <h2 style="margin:16px 0 8px">Workers</h2>
        <div class="scroll"><table id="workers-table"></table></div>
    </div>

    <div class="grid">
        <div class="panel">
            <h2>Pool sizes</h2>
            <p class="muted" style="margin-top:0">
                How many processes each pool runs, and how many consumers the RabbitMQ
                queue gets inside each of them. Applying rolls only the groups that
                changed — the rolling is done by the task pool, the one pool this never
                touches. Raising <code>ws</code> above one puts two browsers on different
                workers, which is what the fanout bus is there for; rolling it drops the
                open sockets, and the page reconnects.
            </p>
            <div class="controls">
                <div class="field">
                    <label for="s-http">http</label>
                    <input id="s-http" type="number" min="{{ $scalingLimits['httpWorkers']['min'] }}" max="{{ $scalingLimits['httpWorkers']['max'] }}" value="{{ $scaling['httpWorkers'] }}" title="HTTP worker processes">
                </div>
                <div class="field">
                    <label for="s-rmq">rabbitmq</label>
                    <div class="pair">
                        <input id="s-rmq" type="number" min="{{ $scalingLimits['rabbitmqWorkers']['min'] }}" max="{{ $scalingLimits['rabbitmqWorkers']['max'] }}" value="{{ $scaling['rabbitmqWorkers'] }}" title="consumer worker processes (0 takes the pool out of the master config)">
                        <input id="s-coro" type="number" min="{{ $scalingLimits['rabbitmqCoroutines']['min'] }}" max="{{ $scalingLimits['rabbitmqCoroutines']['max'] }}" value="{{ $scaling['rabbitmqCoroutines'] }}" title="consumer coroutines the queue gets in each process">
                    </div>
                </div>
                <div class="field">
                    <span></span>
                    <span class="hint">processes &times; consumer coroutines in each</span>
                </div>
                <div class="field">
                    <label for="s-ws">ws</label>
                    <input id="s-ws" type="number" min="{{ $scalingLimits['wsWorkers']['min'] }}" max="{{ $scalingLimits['wsWorkers']['max'] }}" value="{{ $scaling['wsWorkers'] }}" title="WebSocket worker processes (0 takes the pool out of the master config)">
                </div>
                <div class="actions"><button data-run="scaling">Apply</button></div>
            </div>
            <pre id="out-scaling">—</pre>
        </div>

        <div class="panel">
            <h2>Concurrency</h2>
            <p class="muted" style="margin-top:0">
                The same cooperative pause taken N times: as coroutines of one WaitGroup,
                then one after another.
            </p>
            <div class="controls">
                <div class="field"><label for="c-n">tasks</label><input id="c-n" type="number" value="10" min="1" max="200"></div>
                <div class="field"><label for="c-ms">ms each</label><input id="c-ms" type="number" value="200" min="1" max="5000"></div>
                <div class="actions"><button data-run="concurrent">Run</button></div>
            </div>
            <pre id="out-concurrent">—</pre>
        </div>

        <div class="panel">
            <h2>MySQL (<span id="notes-connection">{{ $connection }}</span>)</h2>
            <p class="muted" style="margin-top:0">
                Eloquent over the non-blocking connection. Bulk writes run as coroutines,
                each in a transaction of its own.
            </p>
            <div class="controls">
                <div class="field"><label for="n-title">title</label><input id="n-title" type="text" value="hello" placeholder="title"></div>
                <div class="actions"><button data-run="note">Add note</button></div>
            </div>
            <div class="controls">
                <div class="field"><label for="n-count">bulk</label><input id="n-count" type="number" value="10" min="1" max="50"></div>
                <div class="actions"><button data-run="bulk">Insert concurrently</button></div>
            </div>
            <pre id="out-notes">—</pre>
        </div>

        <div class="panel">
            <h2>Queue (RabbitMQ)</h2>
            <p class="muted" style="margin-top:0">
                One consumer process holds several messages at once — the results come
                back carrying one worker pid.
            </p>
            <div class="controls">
                <div class="field"><label for="j-payload">payload</label><input id="j-payload" type="text" value="hello" placeholder="payload"></div>
                <div class="field"><label for="j-count">jobs</label><input id="j-count" type="number" value="8" min="1" max="50"></div>
                <div class="field"><label for="j-ms">work ms</label><input id="j-ms" type="number" value="1000" min="0" max="10000"></div>
                <div class="actions"><button data-run="job">Dispatch</button></div>
            </div>
            <pre id="out-jobs">—</pre>
        </div>

        <div class="panel">
            <h2>WebSocket <span class="badge muted" id="ws-state">connecting</span></h2>
            <p class="muted" style="margin-top:0">
                The page holds an upgraded connection to one ws worker; the button posts to an
                http worker, which publishes to the fanout bus. The pids below are of two
                different processes — that gap is what the bus crosses.
            </p>
            <div class="controls">
                <div class="field"><label for="w-text">message</label><input id="w-text" type="text" value="hello" placeholder="message"></div>
                <div class="field"><label for="w-count">messages</label><input id="w-count" type="number" value="5" min="1" max="50" title="how many to publish; each arrives as &quot;number text&quot;"></div>
                <div class="field"><label for="w-others">to others</label><input id="w-others" type="checkbox" title="exclude this browser: broadcast()->toOthers()"></div>
                <div class="actions"><button data-run="ws">Broadcast</button></div>
            </div>
            <pre id="out-ws">—</pre>
        </div>

        <div class="panel">
            <h2>Periodic tasks</h2>
            <p class="muted" style="margin-top:0">
                A counter the task pool bumps on its own.
                <code>make tasks-stop</code> freezes it — from another container,
                through a cache key rather than a pid. <code>make sconcur-reload</code>
                starts it again.
            </p>
            <pre id="out-heartbeats">—</pre>
        </div>
    </div>
</div>

<script>
    const $ = (id) => document.getElementById(id);
    const num = (id) => Number($(id).value);

    const bytes = (value) => {
        if (!value) return '0';
        const units = ['B', 'KiB', 'MiB', 'GiB'];
        let index = 0;
        while (value >= 1024 && index < units.length - 1) { value /= 1024; index++; }
        return value.toFixed(index === 0 ? 0 : 1) + ' ' + units[index];
    };

    const show = (id, data) => { $(id).textContent = JSON.stringify(data, null, 2); };

    // Every answer goes through here, because during a roll the page is talking to a
    // pool that is being replaced: nginx answers 502 with an HTML error page, and
    // response.json() on that reports a parse error about an unexpected '<' — which
    // tells the reader nothing about what is actually happening.
    const fetchJson = async (url, options = {}) => {
        let response;

        try {
            response = await fetch(url, options);
        } catch (error) {
            throw new Error('unreachable — the workers may be rolling');
        }

        const body = await response.text();

        try {
            return JSON.parse(body);
        } catch (error) {
            if (response.status === 502 || response.status === 503 || response.status === 504) {
                throw new Error(`the workers are rolling (${response.status})`);
            }

            throw new Error(`${response.status} ${response.statusText || 'unexpected answer'}`);
        }
    };

    const request = async (id, url, options = {}) => {
        $(id).textContent = 'running…';
        try {
            show(id, await fetchJson(url, options));
        } catch (error) {
            $(id).textContent = error.message;
        }
    };

    const post = (body) => ({
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(body),
    });

    const actions = {
        concurrent: () => request('out-concurrent', `/api/concurrent?n=${num('c-n')}&ms=${num('c-ms')}`),
        note: () => request('out-notes', '/api/notes', post({ title: $('n-title').value, body: 'written at ' + new Date().toISOString() })),
        bulk: () => request('out-notes', '/api/notes/bulk', post({ count: num('n-count') })),
        job: () => request('out-jobs', '/api/jobs', post({ payload: $('j-payload').value, count: num('j-count'), work_ms: num('j-ms') })),
        scaling: () => request('out-scaling', '/api/scaling', post({
            httpWorkers: num('s-http'),
            rabbitmqWorkers: num('s-rmq'),
            rabbitmqCoroutines: num('s-coro'),
            wsWorkers: num('s-ws'),
        })),
    };


    document.querySelectorAll('button[data-run]').forEach((button) => {
        button.addEventListener('click', async () => {
            button.disabled = true;
            try { await actions[button.dataset.run](); } finally { button.disabled = false; }
        });
    });

    const row = (cells, tag = 'td') => '<tr>' + cells.map((cell) => `<${tag}>${cell}</${tag}>`).join('') + '</tr>';

    const workCells = (work) => work
        ? [work.inProcess, work.finished, work.refused, work.avgMs.toFixed(1)]
        : ['—', '—', '—', '—'];

    const renderTelemetry = (data) => {
        if (!data.available) {
            $('telemetry-state').innerHTML = `<span class="bad">unavailable — ${data.reason}</span>`;
            $('telemetry-tiles').innerHTML = '';
            $('groups-table').innerHTML = '';
            $('workers-table').innerHTML = '';
            return;
        }

        const hung = data.workersHung > 0 ? 'bad' : 'ok';

        $('telemetry-state').innerHTML = `<span class="ok">${data.name}</span>`;
        $('telemetry-tiles').innerHTML = [
            ['workers', data.workersTotal],
            ['hung', `<span class="${hung}">${data.workersHung}</span>`],
            ['in process', data.totals.work ? data.totals.work.inProcess : '—'],
            ['finished', data.totals.work ? data.totals.work.finished : '—'],
            ['avg ms', data.totals.work ? data.totals.work.avgMs.toFixed(1) : '—'],
            ['cpu %', data.totals.cpuPercent.toFixed(1)],
            ['rss', bytes(data.totals.memoryRssBytes)],
            ['ext tasks', data.totals.runtimeTasks],
            ['master rss', bytes(data.master.memoryRssBytes)],
        ].map(([key, value]) => `<div class="tile"><div class="k">${key}</div><div class="v">${value}</div></div>`).join('');

        $('groups-table').innerHTML =
            row(['group', 'workers', 'hung', 'in process', 'finished', 'refused', 'avg ms', 'cpu %', 'rss', 'ext tasks'], 'th')
            + data.groups.map((group) => row([
                group.name, group.workersTotal, group.workersHung,
                ...workCells(group.work),
                group.cpuPercent.toFixed(1), bytes(group.memoryRssBytes), group.runtimeTasks,
            ])).join('');

        $('workers-table').innerHTML =
            row(['pid', 'group', 'uptime s', 'in process', 'finished', 'refused', 'avg ms', 'cpu %', 'rss', 'ext tasks'], 'th')
            + data.workers.map((worker) => row([
                worker.hung ? `<span class="bad">${worker.pid}</span>` : worker.pid,
                worker.group, worker.uptimeSeconds.toFixed(0),
                ...workCells(worker.work),
                worker.cpuPercent.toFixed(1), bytes(worker.memoryRssBytes), worker.runtimeTasks,
            ])).join('');
    };

    // A failed poll leaves the last numbers on screen and says so in the status line,
    // rather than blanking the tables: a roll takes a few seconds, and a page that
    // empties itself and fills back up is harder to read than one that holds still and
    // tells you it is waiting.
    const poll = async () => {
        try {
            const [telemetry, heartbeats] = await Promise.all([
                fetchJson('/api/telemetry'),
                fetchJson('/api/heartbeats'),
            ]);
            renderTelemetry(telemetry);
            show('out-heartbeats', heartbeats);
        } catch (error) {
            $('telemetry-state').innerHTML = `<span class="warn">${error.message}</span>`;
        }
    };

    poll();
    setInterval(poll, 1000);

    // The ws client, written against the wire protocol rather than through laravel-echo:
    // the demo has no bundler, and the frames are the same ones Echo sends. A real
    // application uses Echo — the README shows the four lines of config it needs.
    const ws = {
        socket: null,
        socketId: null,
        log: [],
    };

    const wsLog = (line) => {
        ws.log.unshift(new Date().toLocaleTimeString() + '  ' + line);

        ws.log = ws.log.slice(0, 12);

        $('out-ws').textContent = ws.log.join('\n');
    };

    // The badge says what is true now; the log says what happened. Both are needed —
    // a log ending in "subscribed" reads the same whether the socket is still open.
    const wsState = (text, tone) => {
        const badge = $('ws-state');

        badge.textContent = text;
        badge.className = 'badge ' + tone;
    };

    const wsConnect = async () => {
        const config = await fetchJson('/api/ws');

        if (config.error || !config.key) {
            wsState('not configured', 'bad');

            wsLog('no app key: set SCONCUR_WS_APP_KEY and start the ws pool');

            return;
        }

        const url = (location.protocol === 'https:' ? 'wss://' : 'ws://')
            + location.host + config.pathPrefix + '/' + config.key + '?protocol=7&client=demo&version=1.0';

        const socket = new WebSocket(url);

        ws.socket = socket;

        socket.onopen = () => { wsState('upgraded', 'warn'); };

        socket.onclose = () => {
            wsState('disconnected, retrying', 'bad');

            ws.socketId = null;

            setTimeout(wsConnect, 2000);
        };

        socket.onmessage = (message) => {
            const frame = JSON.parse(message.data);

            // `data` travels as a string with JSON inside it, both ways. That is the
            // protocol, not an encoding accident.
            const data = typeof frame.data === 'string' ? JSON.parse(frame.data || '{}') : (frame.data || {});

            if (frame.event === 'pusher:connection_established') {
                ws.socketId = data.socket_id;

                // The socket id is the worker pid and a counter, so the badge also says
                // which of the ws workers this browser landed on.
                wsState('connected as ' + data.socket_id, 'ok');

                socket.send(JSON.stringify({event: 'pusher:subscribe', data: {channel: config.channel}}));

                return;
            }

            if (frame.event === 'pusher_internal:subscription_succeeded') {
                wsLog('subscribed to ' + frame.channel);

                return;
            }

            if (frame.event === 'pusher:ping') {
                socket.send(JSON.stringify({event: 'pusher:pong', data: {}}));

                return;
            }

            if (frame.event === 'demo.message') {
                wsLog('from ' + data.source + ' pid=' + data.workerPid + ': ' + data.text);

                return;
            }

            wsLog(frame.event + ' ' + JSON.stringify(data));
        };
    };

    const wsBroadcast = async () => {
        const answer = await fetchJson('/api/ws/broadcast', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-Socket-ID': ws.socketId || ''},
            body: JSON.stringify({
                text: $('w-text').value,
                count: num('w-count'),
                others: $('w-others').checked ? 1 : 0,
            }),
        });

        if (answer.error) {
            wsLog('publish failed: ' + answer.error);

            return;
        }

        wsLog('published ' + answer.published + ' from http pid=' + answer.workerPid
            + (answer.toOthers ? ' (to others)' : ''));
    };

    document.querySelector('[data-run="ws"]').addEventListener('click', wsBroadcast);

    wsConnect();
</script>
</body>
</html>
