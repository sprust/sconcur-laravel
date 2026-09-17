---
description: Wait for a new sconcur/sconcur release (polling every 30 seconds), then install it and adapt the package to it on the current branch
argument-hint: "[extra instructions, e.g. \"create branch feature/sconcur-0.14\"]"
---

# Wait for a sconcur/sconcur release and adapt the package to it

Extra instructions from the user (may be empty; they take precedence over the defaults
below where they conflict, e.g. they may ask for a new branch or a commit):

<extra-instructions>
$ARGUMENTS
</extra-instructions>

Read `.ai/README.md` first and follow it — code style, bilingual docs, `make check`,
commit rules. The steps below only add what is specific to this task.

## Constraints

- **Another agent works in this repository at the same time and edits code.** Never
  `git stash`, `git reset`, `git checkout -- <path>`, `git clean`, or otherwise discard or
  rewrite changes you did not make. Before touching a file, look at `git status` /
  `git diff` for it; if it carries someone else's uncommitted edits, work around them
  instead of overwriting. When staging, stage only your own files by path — never
  `git add -A` / `git add .`.
- Work on the current branch. Create a branch only if the extra instructions say so
  (`git switch -c <name>` — it carries the working tree, which is fine).
- Do not commit or push unless the extra instructions say so. Otherwise finish with a
  proposed commit message (short imperative subject, `Co-Authored-By` trailer only).
- Adapting means: the package builds, passes `make check` and is correct against the new
  version. Do not integrate new library features on your own initiative — list them as
  suggestions at the end instead, unless the extra instructions ask for it.

## 1. Wait for the release

The current version is the one `composer.lock` resolved:

```bash
jq -r '.packages[] | select(.name=="sconcur/sconcur") | .version' composer.lock | sed 's/^v//'
```

A new version counts as released only when **both** are true, because the Docker image
downloads `sconcur.so` from the GitHub release of the exact version in `composer.lock`:

- Packagist lists a stable version higher than the current one
  (`https://repo.packagist.org/p2/sconcur/sconcur.json`);
- the GitHub release `v<version>` of `sprust/sconcur` has the `sconcur.so` asset
  (`https://github.com/sprust/sconcur/releases/download/v<version>/sconcur.so` answers
  200 to `curl -fsIL`).

Poll with a background Bash command (`run_in_background: true`) so the wait does not hold
the session; it exits and prints the version once found, and you are re-invoked then.
Save the script to the scratchpad directory and run it, for example:

```bash
#!/usr/bin/env bash
set -u
cd "<repository root>"
current="$(jq -r '.packages[] | select(.name=="sconcur/sconcur") | .version' composer.lock | sed 's/^v//')"
echo "current: $current, polling every 30 seconds"
while true; do
    latest="$(curl -fsS --max-time 20 https://repo.packagist.org/p2/sconcur/sconcur.json \
        | jq -r '.packages["sconcur/sconcur"][].version' \
        | sed 's/^v//' | grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' | sort -V | tail -1)"
    if [ -n "$latest" ] && [ "$latest" != "$current" ] \
        && [ "$(printf '%s\n%s\n' "$current" "$latest" | sort -V | tail -1)" = "$latest" ] \
        && curl -fsIL --max-time 20 -o /dev/null \
            "https://github.com/sprust/sconcur/releases/download/v${latest}/sconcur.so"; then
        echo "NEW_VERSION=$latest"
        exit 0
    fi
    sleep 30
done
```

If a version newer than the current one is already published when the command starts,
proceed immediately. Tell the user which version you are waiting from, then wait.

## 2. Snapshot the old library

Before installing, copy `vendor/sconcur/sconcur` to the scratchpad directory — the diff
between it and the new vendor is the primary source for what changed. If the library
source is cloned next to this repository (`../sconcur-php`, remote `sprust/sconcur`),
also `git fetch --tags` there and use `git log v<old>..v<new>` and
`git diff v<old>..v<new>` (read-only — do not change that repository).

## 3. Install

1. Pin the new version exactly in `composer.json` (`"sconcur/sconcur": "<new>"`, no caret).
2. `make composer-lock c="sconcur/sconcur --with-all-dependencies"` — resolves the lock
   in a throwaway container. If the new version needs a different platform
   (`config.platform` php / `ext-msgpack`) or other constraint changes, adjust
   `composer.json` and the Dockerfile accordingly, then repeat.
3. `make build` (bakes the new `sconcur.so` from the lock), `make up`,
   `make composer c=install`, `make workers-restart`.
4. Confirm: `docker compose exec php php -r 'echo phpversion("sconcur"), PHP_EOL;'` (or
   `php -m`) and `jq` on `composer.lock` show the new version.

Rebuilding and restarting containers affects the other agent's running tests — say so in
the final report.

## 4. Adapt

Study the diff of the library and change this package where it is affected:

- public API used under `src/`, `tests/`, `workbench/`, `demo/` — renamed or removed
  classes and methods, changed signatures, new required arguments, changed exceptions;
- `config/sconcur.php` — a full mirror of `vendor/sconcur/sconcur/config/sconcur.servers.config.json`;
  bring it back in line with the new file, and the `.env.example` / demo / workbench
  config that feed it;
- server CLI flags forwarded to workers (`HttpStartCommand`, `RabbitmqConsumerStartCommand`,
  `WsStartCommand` must declare every flag of the `server` block);
- places that pin or cite the version: `grep -rn "<old version>" --exclude-dir=vendor .`
  (`docs/*.md` and `docs/*.ru.md` as a pair, `.ai/README.md`, comments such as the one
  in `src/Redis/Dsn.php`) — update each only if the claim is still true for the new
  version, re-verifying it against the new code;
- behavioral changes the library's own docs or changelog describe for features this
  package wraps (Redis, MySQL, AMQP, HTTP, WebSocket, workers, master).

Then run `make check` and fix what it reports. If a failure comes from the other agent's
uncommitted work rather than from the upgrade, do not fix it — report it.

## 5. Report

Finish with:

- old → new version, and the evidence it is installed;
- what changed in the library that matters here, and what you changed in response
  (file paths);
- `make check` result;
- new library features worth integrating, as suggestions;
- anything left undone or uncertain;
- the proposed commit message (or the commit you made, if the extra instructions asked).
