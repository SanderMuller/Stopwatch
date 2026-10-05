---
name: stopwatch-debug
description: "Debug control flow and runtime values with sandermuller/stopwatch probes. Activate when the user mentions: why is this null, wrong value, which branch runs, unexpected result, works locally but not, trace this, add logging, printf debugging, find where it goes wrong, inspect state, debug this command, debug this job, flaky behaviour. For slowness use stopwatch-profile instead."
---

# Debug a code path with probes

`stopwatch()->probe('label', [...values])` records a label, the values you pass, the file and line, and the time since the previous probe. With debug mode on, every probe is appended at once to a JSONL file for the run, so it survives an exception, `exit()` or `dd()`, and works in requests, commands, queued jobs and tests. You read the run with one command.

## When to use this skill

- You must learn which path the code took, or which value reached a point.
- You need several probes in one run, read together, in order.
- The reproduction is a test, an artisan command, a queued job, or an HTTP request.

Do NOT use it for:
- One value in one place. Use `dump()` in a test.
- Step-by-step inspection of a deep call stack. Use Xdebug.
- Slowness. Use the `stopwatch-profile` skill.

## Workflow

### 1. Write the hypothesis first

Write one sentence: "I expect X at point A; I think it becomes Y at point B." Place probes to prove or disprove it. Do not place probes at random.

### 2. Turn debug mode on

```dotenv
STOPWATCH_DEBUG=true
```

That one switch turns on the run log, the JSONL stream and the Location column, and records every run (no 50 ms minimum, empty runs kept, `full` detail). It also starts and finishes a run for each artisan command and queued job. Query and HTTP counts per probe still need `STOPWATCH_TRACK_QUERIES=true` / `STOPWATCH_TRACK_HTTP=true`.

Debug mode works when `APP_DEBUG=true` or `APP_ENV` is `local` or `testing` (so a test run with `APP_DEBUG=false` still records), and never under Octane. If it is blocked, `stopwatch:runs:list` and `stopwatch:runs:show latest` say why, also when the blocked run was another process (for example `Debug mode was blocked in another process at …: app.debug is false and APP_ENV is staging.`).

### 3. Place probes

```php
stopwatch()->probe('order total before discount', ['order' => $order->id, 'total' => $total]);
```

- Use `probe()`, not `checkpoint()`, for temporary lines, and always write it as `stopwatch()->probe(`. Step 7 finds them by that exact text; a bare `->probe(` also matches unrelated `probe()` methods in the app.
- Pass scalars or small arrays. Pass `$model->id` or `$model->only([...])`, not the whole model. Each value is capped at 4096 bytes in the stream.
- Put one probe on each side of a branch you suspect: `probe('took discount branch', [...])`.
- 3 to 10 probes per run. More hides the signal. A loop is capped at 1000 records per run.
- Never pass secrets. Metadata is written to disk as passed.

### 4. Reproduce

Run the test or the command yourself. Ask the user to trigger an HTTP request only when you cannot.

In a PHPUnit test, Laravel does not fire command events, so a command run inside a test is not finished automatically. The probes are still written; the run shows state `shutdown` after the test process ends.

### 5. Read the run

```bash
php artisan stopwatch:runs:show latest
php artisan stopwatch:runs:show latest --format=json   # raw records, lossless values
php artisan stopwatch:runs:list --limit=5              # when several runs happened
```

In a package without an app, use `vendor/bin/testbench` instead of `php artisan`.

Read the table top to bottom. Rows are in execution order.
- A probe that is missing means its code did not run.
- The Metadata column holds the values as JSON; the Location column holds `file:line`.
- State `unfinished` means the process died without running PHP shutdown (memory limit, time limit, killed), or is still running.
- A run with `threw: true` has a `## Exception` section with the class and `file:line`.

If no run appears: read the message of `stopwatch:runs:show latest` (it names a blocking guard), and check that the code with the probes ran at all.

Compare what you read with the hypothesis. Move the probes closer to the fault, then repeat steps 4 and 5.

### 6. Verify the fix

Reproduce again with the probes still in place, then compare the two runs:

```bash
php artisan stopwatch:runs:diff <before-id> latest
```

The diff shows per probe the time change and the metadata values that changed (`total: 100 -> 90`), plus probes that ran in one run only.

### 7. Remove the probes

```bash
grep -rn --include='*.php' --exclude-dir=vendor --exclude-dir=node_modules -F 'stopwatch()->probe(' .
```

Remove every probe the grep finds (a probe can span several lines), and run the grep again to confirm it finds nothing. Revert `STOPWATCH_DEBUG`. Clear the runs with `php artisan stopwatch:runs:clear --force`.

If the project includes `vendor/sandermuller/stopwatch/resources/phpstan/no-probes.neon` in its PHPStan config, PHPStan fails while a probe is left in the code.

### 8. Report

Run the test or command again without the probes. Report the result and the cause you found, not the probe output.

## MCP alternative

When the agent has no shell, the same reads are MCP tools: `stopwatch-list-runs`, `stopwatch-show-run` (id `latest`), `stopwatch-diff-runs` and `stopwatch-clear-runs` (needs `confirm: true`). They are available in Laravel Boost's MCP server, or as a separate server with `php artisan mcp:start stopwatch` when `laravel/mcp` is installed.
