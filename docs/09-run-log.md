# Persistent run log

Every finished run is written to `storage/stopwatch/runs/<ULID>.md`, so a slow request can be read after the fact instead of reproduced. Off by default.

```dotenv
STOPWATCH_LOG_RUNS=true
```

Pair it with `StopwatchMiddleware` for HTTP runs. For commands and queued jobs, set `STOPWATCH_LOG_AUTO_LIFECYCLE=true`: the outermost command and each queued job then become a run of their own, and a job on the sync queue inside a request or command joins that run. Without it, call `stopwatch()->finish()` yourself. Runs faster than `STOPWATCH_LOG_MIN_DURATION_MS` (default `50`) are skipped.

[Debug mode](#debug-mode) (`STOPWATCH_DEBUG=true`) turns all of this on and also writes each checkpoint to disk as it happens.

Each file holds the same markdown `stopwatch()->toMarkdown()` produces, plus `## SQL detail` and `## HTTP detail` in `full` mode, [`## Exception`](10-crash-diagnostics.md) when something threw, and `## Context` when the context collector is on. YAML frontmatter keeps listing cheap.

## Inspect runs

```bash
php artisan stopwatch:runs:list --slow --limit=10
php artisan stopwatch:runs:show <id>          # or: latest
php artisan stopwatch:runs:clear
```

The list has a **State** column: `finished`, or for a run that only has a debug stream, `shutdown`, `restarted` or `unfinished` (see [Debugging with probes](11-debugging.md#read-the-run)).

<details>
<summary>Filtering and scheduled cleanup</summary>

```bash
php artisan stopwatch:runs:list --threw
php artisan stopwatch:runs:list --exception-class=ValidationException
php artisan stopwatch:runs:list --ctx tenant_id=acme --ctx user_id=42
php artisan stopwatch:runs:list --format=json
```

Pruning is probabilistic and in-process (5%). For a predictable schedule:

```bash
0 3 * * * php artisan stopwatch:runs:clear --days=7 --force
0 3 * * * php artisan stopwatch:runs:clear --keep=200 --force
```

</details>

## Debug mode

`STOPWATCH_DEBUG=true` also writes each checkpoint to `storage/stopwatch/runs/<ULID>.jsonl` the moment it happens, beside the run's markdown file, so a run that never reaches `finish()` can still be read. [Debugging with probes](11-debugging.md) covers the loop: `probe()`, `stopwatch:runs:show latest`, the run states, `stopwatch:runs:diff`, and the limits.

## Debugging a slow request

1. Set `STOPWATCH_LOG_RUNS=true`. Register `StopwatchMiddleware::autoStart()` for HTTP; for commands and jobs set `STOPWATCH_LOG_AUTO_LIFECYCLE=true`, or call `start()` and `finish()` yourself. Add checkpoints along the suspect path.
2. Reproduce the slow path.
3. `php artisan stopwatch:runs:list --slow --limit=10`
4. `php artisan stopwatch:runs:show <id>` on the worst offender.
5. Read the **Share** column and find the row that owns most of it:
   - high `q` on one row: an N+1 candidate. Set `STOPWATCH_LOG_DETAIL=full` and reproduce to see the SQL.
   - high `h`: an outbound API loop. Same flag adds method, URL and status per call.
   - `queries_total` far above the sum of per-checkpoint queries: work is happening after your last checkpoint. Add one near the response and re-profile.
6. Split the hot row with more checkpoints inside it. Fix, and go back to step 2.

## Limitations

**Laravel only, and not supported under Octane or Swoole.** Debug mode refuses to start there. The `Stopwatch` singleton keeps per-run state in memory, which is unsafe for concurrent coroutines. Keep `STOPWATCH_LOG_RUNS=false` under Octane until the lifecycle is per-request.

`Stopwatch::dd($exception)` does not capture the exception: `dd()` calls `finish()` before inspecting its arguments, so the recorder runs first. Use `$stopwatch->withTransientContext(Stopwatch::TRANSIENT_EXCEPTION, $e)->dd()`.

Writes never throw. A disk failure is logged via `logger()->warning()` and the request completes.
