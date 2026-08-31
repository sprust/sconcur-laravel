# SConcur Laravel — Agent & Contributor Guide

The single source of truth for AI agents (Claude Code, etc.) and human contributors
working in this repository. `CLAUDE.md` and `AGENTS.md` both point here.

> **IMPORTANT:** These instructions override default behavior — follow them exactly.

## Project

SConcur Laravel is the Laravel integration for
[SConcur](https://github.com/sprust/sconcur), a PHP concurrency library backed by a
Go extension. It gives an application three runtimes and a coroutine-scoped container:

- **HTTP server** — each request runs in its own PHP Fiber inside one long-lived
  process (`src/Http/`).
- **RabbitMQ consumer pool** — one process reading several queues as coroutines
  instead of one blocking `queue:work` per worker (`src/Queue/Rabbitmq/`).
- **Periodic task pool** — every configured task is a coroutine of one `WaitGroup`
  (`src/Tasks/`).

All three are pools of one supervisor process, the SConcur **master**
(`src/Servers/MasterRunner.php`), configured as `groups` in `config/sconcur.php`.

The container is coroutine-scoped in every process, with nothing to turn on: config,
events, router, translator and view are swapped for coroutine-safe adapters, and
`request`/`session`/`auth`/`cookie` resolve per coroutine. Outside a coroutine the
context is the process root — one store for one caller, which is what the stock
implementations are.

## Further reading

- [README.md](../README.md) — package overview, artisan commands, ENV reference
- [docs/fiber-safe-laravel-bridge.ru.md](../docs/fiber-safe-laravel-bridge.ru.md) —
  why Octane's model is not fiber-safe and what replaces it here
- [docs/sconcur-coroutine-context.ru.md](../docs/sconcur-coroutine-context.ru.md) —
  the coroutine context this package builds on
- [docs/task-pool.ru.md](../docs/task-pool.ru.md) — the periodic task pool
- [demo/README.md](../demo/README.md) — the demo application
- [.ai/plans/](plans/) — detailed designs for roadmap items

## Plans

The README keeps only short, user-facing prose. Detailed designs live in `.ai/plans/`
— one Markdown file per plan, or a directory per multi-step effort
(`.ai/plans/init/`). When a task grows beyond a sentence (mechanics, API sketch,
trade-offs, open questions), put the detail there. **Plans are written in Russian** (a
maintainer decision). Code identifiers, paths and code blocks stay as-is.

**Plans are a development-only artifact.** Never link to `.ai/plans/*` (or reference
the directory) from `README.md` or from anything under `docs/` — those are
user-facing. Plan links belong only here, in `.ai/`, and in other `.ai/plans/` files.

## Build & run

Requires Docker. All commands via `make`:

```bash
make setup              # everything from scratch: env, build, up, install, migrate, declare
make env-copy           # copy .env.example → .env (first time)
make build              # build docker images
make up / make stop     # start / stop containers
make demo-art c=...     # artisan of the demo application
make workers-art c=...  # the same artisan inside the workers container
make queues-declare     # declare the RabbitMQ queues the consumer pool reads
make test               # PHPUnit
make test c=--filter=X  # one test
make stan               # PHPStan level 8
make cs-fixer-check     # check code style
make cs-fixer-fix       # auto-fix code style
make check              # cs-fixer, phpstan, tests
make sconcur-status     # master status: groups and workers
make sconcur-reload     # rolling restart of the worker pools
```

After `make setup` the demo application answers on `http://localhost:${APP_PORT}`
(8080 by default).

`make test` needs the containers up: the tests load `sconcur.so` and the integration
ones talk to the live MySQL and RabbitMQ.

## Environment

Five containers, prefix `scl-`:

- `nginx` — the only published entry point; proxies to the HTTP pool
- `php` — CLI only (composer, artisan, phpunit, analyzers). There is no php-fpm in
  this repository: HTTP is served by SConcur itself.
- `workers` — supervisor running `php demo/artisan sconcur:servers:master:start`
- `mysql`, `rabbitmq` — **in-memory** (`tmpfs`), state is wiped when the container is
  recreated. The named-volume mounts sit beside them, commented out.

`sconcur.so` is baked into the image: `docker/php/Dockerfile` reads the pinned
`sconcur/sconcur` version out of `composer.lock` and downloads the matching release
asset. **`composer.lock` must stay committed** — without it a fresh clone has nothing
to pin against. The library version is pinned exactly (`0.11.0`, not a caret): the
`.so` and the PHP side cross a protocol boundary that changes with the version.

## Architecture

```
src/SConcurServiceProvider.php  — registers commands, the queue connector, the
                                  sconcur_mysql driver, the task pool and the adapters
src/Foundation/                 — AsyncApplication (coroutine-scoped container),
                                  ScopedService, ScopedServiceProxy
src/Config/AsyncConfig          — config()->set overlay per coroutine
src/Events/AsyncDispatcher      — defer() per coroutine
src/Routing/AsyncRouter         — current route and request per coroutine
src/Translation/AsyncTranslator — locale per coroutine
src/View/AsyncViewFactory       — View::share per coroutine
src/Http/                       — HttpServerRunner + LaravelHttpHandler
src/Servers/MasterRunner        — wrapper over SConcur\Worker\MasterCli
src/Console/                    — artisan commands
src/Queue/Rabbitmq/             — Connector, Queue, Job, ConsumerRunner
src/Database/CoroutineTransactionsManager, TransactionStore
src/Database/Mysql/             — Connector, Connection, Dsn, TransactionStack,
                                  TransactionState
src/Tasks/                      — TaskPool, TaskPoolController, TaskRegistry,
                                  CooperativeSleeper, TaskPoolTelemetry, TaskPoolMetrics
src/Tasks/Control/              — stop/restart through a cache key, from any container
```

Points worth knowing before changing anything:

- **The config is published, not merged.** `config/sconcur.php` is a skeleton; the
  application owns every value including the defaults. Merging would let a deleted key
  quietly come back. Commands say so rather than running on an empty array.
- **The adapters are installed in every process**, with no mode to detect. There used
  to be an argv check and it was wrong — the master spawns a worker as
  `artisan --address=… sconcur:servers:http:start --masterPid=N`, so the command name
  is not argv[1] and the check answered no in the very processes it existed for.
- **A group's `server` block is forwarded to its workers' argv verbatim.** Symfony
  Console rejects flags a command does not declare, so `HttpStartCommand` and
  `RabbitmqConsumerStartCommand` declare every one of them even though
  `HttpServer::fromArgs` / `QueueConsumer::fromArgs` are what read them.
- **Nothing declares RabbitMQ topology.** Neither the publishing driver nor
  `QueueConsumer`. `sconcur:rabbitmq:declare` must run before the first publish and
  before the pool starts — it is on every install path (`make setup`), not a one-off.
- **`SCONCUR_RABBITMQ_WORKER_COUNT=0` removes the group from the config entirely**, it
  does not set the pool to zero workers: to the master `workerCount: 0` means one
  worker per CPU. The `array_values(array_filter(...))` around the group list is load
  bearing — `array_filter` preserves keys, and `MasterConfig::parseGroups` refuses a
  non-list.

## Tests

- `tests/Feature/` — PHPUnit feature tests, `orchestra/testbench` based
- `workbench/` — the testbench application the tests run against (models, jobs, tasks,
  routes, config). It is **not** the demo; see below.
- `demo/` — the demo application, a minimal Laravel skeleton the master serves. Not
  used by tests.

Namespaces: `SConcur\Laravel\Tests\` → `tests/`, `Workbench\App\` → `workbench/app/`,
`Demo\App\` → `demo/app/`.

The demo is not a workbench because testbench builds
`Illuminate\Foundation\Application` itself, and the only hook to replace it
(`workbench/bootstrap/app.php`) is read on the test-case path only, not on the
`vendor/bin/testbench` path. The demo needs `AsyncApplication`, so it carries its own
`bootstrap/app.php`.

## Code style

- PHP 8.4, PSR-12 plus the repository rules in `php-cs-fixer.dist.php`; PHPStan level 8
- Aligned assignments; 4 spaces, LF line endings, ~120 column guide from `.editorconfig`
- `declare(strict_types=1);` in every PHP file — `make declare-strict` lists the ones
  missing it
- `readonly` classes for DTOs; namespaces mirror directory paths
- Classes PascalCase; methods and properties camelCase; all traits carry a `*Trait`
  postfix, so a `use` line is recognizable at a glance
- Code must be maximally typed (parameters, return types, properties)
- Prefer short arrays (`[]`)
- Do **not** use `final` on classes — keep them extendable
- Do not declare global or namespaced helper functions; expose behavior through classes
  and static entry points (`SConcur\Context\Context::current()`)
- **Never write a leading `\` on a class name** — import it with `use` and refer to the
  short name. `use Stringable;` then `implements Stringable`, not `implements
  \Stringable`; the same for `\DateTimeImmutable`, `\Throwable` and every other global
  class. Imported function names follow the same rule. This keeps the imports at the
  top of a file an honest inventory of what it depends on.

### Language

**English everywhere except the Russian documentation.** Russian belongs in exactly two
places: the `docs/*.ru.md` files and `.ai/plans/`, which is written in Russian by a
maintainer decision.

Everything else is English, with no exceptions: code and its comments, PHPDoc,
exception and log messages, test names and failure messages, shell scripts including
everything they print, and commit messages.

`README.md` is currently Russian — a legacy of the package living inside another
repository. Bringing the documentation to the bilingual pair layout the neighbouring
projects use (`X.md` / `X.ru.md` with a language switcher on the first line) is planned
separately; until then do not add new Russian to files without a `.ru` infix.

### Naming

- Never abbreviate variable names — `$exception`, not `$e`; `$request`, not `$req`.
- A variable holding a class instance is named exactly after that class, in
  lowerCamelCase: `CreateBookingHotelAction` → `$createBookingHotelAction`.
- **A property, parameter or constant holding a measured quantity must carry its unit
  in the name**: `timeoutMs`, `memoryRssBytes`, `intervalSeconds`, `sleepChunkMs`.
  Applies to new and changed code. Codes and identifiers that are not a measured
  quantity (`statusCode`) are exempt.

### Formatting

- Separate every `{}` block with blank lines, and separate logical blocks inside a
  method with a blank line — group variable declarations, then method calls, then the
  return.
- Use **named arguments** when calling a project method or constructor that has more
  than one parameter, or at least one optional parameter. Built-in PHP functions are
  exempt.
- When a call uses named arguments, lay them out vertically — one argument per line,
  with a trailing comma.
- A call is formatted uniformly: either all arguments on one line, or every argument on
  its own line. Mixed style is forbidden.
- A signature need not be vertical if the line stays within 120 characters.
- Arrays with two or more elements: one element per line, trailing comma.
- In conditions that mix `&&` and `||`, and in ternaries, wrap condition groups in
  parentheses.

## Exceptions

**No `@throws` anywhere.** Everything here descends from `RuntimeException` or
`LogicException`, both unchecked by PHP convention, so a tag adds nothing a reader can
act on — and a partial list is worse than none: PHPStan reads `@throws` as exhaustive
and kills the `catch` blocks for whatever the list left out.

The current state of `src/` is that it throws built-in exceptions directly
(`RuntimeException` in `AbstractSconcurCommand`, `Database\Mysql\Connection`,
`Database\Mysql\TransactionStack`, `Tasks\TaskRegistry`; `InvalidArgumentException` in
`Translation\AsyncTranslator`). The library it integrates with does the opposite — it
names a class per case under `SConcur\Exceptions\`. Aligning this package with that is
worth doing but has not been done; write new code the way the surrounding file does
and do not mass-convert as a side effect of an unrelated change.

## Documentation style

- **Verify every technical claim against the code before writing it** — class and
  method names/signatures, option names and defaults, enum cases, CLI flags, file
  paths, behavioral claims. Fix inaccuracies; never guess.
- **Dry and compact.** Short sentences, no marketing metaphors, no long reasoning
  around a fact a table already states.
- **Minimal bold.** Use `**bold**` only for a genuinely critical warning or a couple of
  key terms — heavy bolding is the top "AI-generated" tell.
- **Do not put source line numbers in docs** — they go stale. Reference file paths only.
- **No unexplained jargon.** Say what happens in plain words.
- **Diagrams in Mermaid.** No `<br/>` anywhere — some renderers print it literally; use
  single-line node labels. For a request+response between two components use one
  bidirectional edge `A <-->|"..."| B`, never two opposing edges. In `flowchart TB`
  declare the caller first so it renders on top. Label edges with the real call names.

## Workflow rules

- Always wait for explicit user approval before committing or pushing, and always
  propose a commit message before committing.
- Never create a git branch without an explicit, direct instruction from the user.
  Work on and commit to the current branch (normally `master`).
- Before implementing any task, propose a plan and wait for explicit user approval.
- After any PHP changes run `make check` and fix what it reports, without asking.
- Do not run post-change commands after documentation-only edits (`*.md`, `.env*`)
  unless they also change executable behavior.

## Answering & code references

When referring to any class, method, or code fragment in a reply, always give the full
path from the project root plus the line number, so the reference is clickable and
jumps straight to the spot in the IDE: whole file `src/Tasks/TaskPool.php`, specific
spot `src/Tasks/TaskPool.php:16`. The line number is required when pointing at concrete
logic; it may be omitted only when referring to a file as a whole. (This applies to
replies — docs carry no line numbers.)

## Commit & pull request guidelines

Short, imperative subjects (`add demo application`, `pin sconcur extension version`).
Pull requests explain the behavioral change and list the validation performed
(`make check`, targeted tests).

When an AI agent creates a git commit itself, it must add a sign-off trailer
identifying the agent:

```
Co-Authored-By: <agent name> <email>
```

**The name must carry the model version the commit was actually written by** — read it
from the running session, never copy the version from an example or from an earlier
commit. Format, with the version standing in for whatever is current:
`Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>` for Claude Code,
`Co-Authored-By: OpenAI Codex <noreply@openai.com>` for OpenAI Codex.
