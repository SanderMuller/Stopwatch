# Run Log

## Overview

Persist a markdown record of every finished stopwatch run to disk (default `storage/stopwatch/runs/<ULID>.md`) so an AI skill — or a human — can later list, sort, and inspect slow runs without re-running the workload. Mirrors the pattern Livewire Blaze uses for `storage/blaze/traces/<ULID>.json`, but stores YAML-frontmatter + markdown bodies (reusing `Stopwatch::toMarkdown()`) so files are LLM-readable as-is and skill consumption is `cat <file>` cheap.

Trigger: `STOPWATCH_LOG_RUNS=true` in `.env`. Off by default (parity with `track_queries`, `track_http`, etc.). Independent of the existing `notification_channels` mechanism — notifications are *threshold-gated alerts*, run logs are *unconditional debug history*.

**Scope (v1):** Laravel only. Octane/Swoole are explicitly **out of scope** until the Stopwatch singleton becomes per-request.

---

## 1. Prior art (Livewire Blaze)

Researched at `livewire/blaze@main` — relevant pieces:

- `src/DebuggerStore.php` — one file per request named by ULID (`storage/blaze/traces/<ULID>.json`). ULID gives chronological sort + per-request isolation.
- `ensureDirectoryExists()` writes a `.gitignore` containing `*.json` on first create, so trace dumps never leak into commits.
- `autoPrune()` runs with **5% probability per write**, pruning files older than 24h. No cron.
- Three artisan commands: `blaze:trace:list/show/clear`. Boost skill `blaze-optimize` consumes them via `php artisan` shellouts, never reads JSON files directly.

What we copy: ULID filenames, auto-`.gitignore`, probabilistic auto-prune, three-command CLI surface, skill-via-artisan consumption.

What we change: **markdown w/ YAML frontmatter** instead of JSON. Reasons:

1. Stopwatch already has a battle-tested `toMarkdown()` — reuse 100% for the body.
2. Skill consumption is plain `cat` — no JSON-to-prose render step.
3. Frontmatter solves the `list` perf problem cheaply: read the first ~1KB per file, parse `---` block, never load the body.
4. Stopwatch is flat (linear checkpoint list), not a recursive call tree like Blaze components — so we don't need JSON's structural nesting.

---

## 2. File layout

```
storage/stopwatch/runs/
├── .gitignore                    # auto-written: *.md
├── 01HZ8K9X4N5P2Q3R4S5T6U7V8W.md
├── 01HZ8K9YA1B2C3D4E5F6G7H8I9.md
└── ...
```

### 2.1 File contents

```markdown
---
id: 01HZ8K9X4N5P2Q3R4S5T6U7V8W
recorded_at: 2026-04-29T14:23:12.487+00:00
duration_ms: 487
checkpoints: 8
url: /admin/users
method: GET
status: 200
command: null
queries_total: 32
query_ms_total: 245
http_total: 4
http_ms_total: 120
memory_delta_bytes: 2415616
slow_threshold_ms: 50
exceeds_slow_threshold: true
---

# Stopwatch profile

- **Total:** 487ms
- **Checkpoints:** 8
- **Window:** 14:23:12.000 → 14:23:12.487
- **Slow threshold:** 50ms
- **Queries (total):** 32 in 245ms
- **HTTP calls (total):** 4 in 120ms
- **Memory delta (total):** +2.3MB

| # | Checkpoint | Δ | Cumulative | Share | Slow | Queries | HTTP | Memory Δ | Metadata |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | Validated input | 2.1ms | 2ms | 0.4% |  | 0q in 0ms | 0h in 0ms | +12.4KB |  |
| 2 | Loaded user | 145ms | 147ms | 29.8% | 2.9× | 18q in 132ms | 0h in 0ms | +1.1MB | id=42 |
...
```

The body below `---` is exactly the existing `Stopwatch::toMarkdown()` output — no changes to that method.

**Important:** frontmatter totals (`queries_total`, `http_total`, etc.) come from new whole-run accumulators (§4.4), **not** from summing per-checkpoint deltas. The body still shows per-checkpoint deltas exactly as today; the frontmatter is the only place where work-after-last-checkpoint counts toward totals.

When `STOPWATCH_LOG_DETAIL=full`, an extra section is appended:

```markdown
## SQL detail

| # | Checkpoint | Duration | SQL |
| --- | --- | --- | --- |
| 2 | Loaded user | 12.3ms | select * from users where id = ? |
...

## HTTP detail

| # | Checkpoint | Method | URL | Status | Duration |
| --- | --- | --- | --- | --- | --- |
| 5 | Fetched orders | GET | https://api.example.com/orders | 200 | 87ms |
...
```

SQL bindings are **never** persisted in `summary` or `full` mode — only the parameterised SQL text. Bindings are an explicit opt-in via `STOPWATCH_LOG_INCLUDE_BINDINGS=true` (off by default; documented as a PII risk). HTTP URLs are already stripped of query strings at capture time (`Stopwatch::stripUrlQueryString` at `src/Stopwatch.php:467`).

These come from `StopwatchCheckpoint::queryCalls` / `httpCalls` (capped at 50 each per checkpoint at `src/Stopwatch.php:64,90`).

### 2.2 Frontmatter format

The frontmatter parser is intentionally tiny and rigid:

- Block delimiters: `---` on its own line.
- Each line inside the block: `^([a-z_]+): (.*)$` — split on **first** `:` only (so `command: cache:clear` parses as `command` = `cache:clear`).
- Values are plain strings: ULIDs (alnum), ISO-8601 timestamps, integers, floats, `true`/`false`/`null`, and simple text.
- No quoting, no nesting, no multiline values, no comments.
- Writer must guarantee no `\n` or `\r` in any value (URLs are path-only, command names cannot contain newlines, labels never enter frontmatter).

This shape lets us write a 30-line parser in `RunLogStore` without pulling in `symfony/yaml`.

### 2.3 Path resolution

| Source                                | Path used                                       |
|---------------------------------------|------------------------------------------------|
| `STOPWATCH_LOG_DIR` env set           | exact value                                     |
| Otherwise                             | `storage_path('stopwatch/runs')`                |

If `storage_path()` is unavailable (i.e. running outside Laravel), the run log feature **disables itself** rather than write to `/tmp`. Logged once via `logger()->warning()` on first attempt. Run log is a Laravel-only feature in v1.

### 2.4 Pruning

Two independent caps, both applied by `RunLogStore::prune()`:

- **Count cap** (`STOPWATCH_LOG_MAX_FILES`, default `200`) — deterministic. Triggered on **every** write that exceeds the cap; deletes the oldest by ULID until we are back under it.
- **Age cap** (`STOPWATCH_LOG_MAX_AGE_DAYS`, default `7`) — probabilistic. **5% chance** per write; deletes any file with ULID timestamp older than N days.

Two triggers because count is the predictable disk guarantee (must run every write) and age is the cleanup nicety (cheap to amortize).

`php artisan stopwatch:runs:clear` exposes both caps on demand.

---

## 3. Configuration

Append to `config/stopwatch.php`:

```php
'run_log' => [
    'enabled' => (bool) env('STOPWATCH_LOG_RUNS', false),
    'path' => env('STOPWATCH_LOG_DIR'), // null → storage_path('stopwatch/runs')
    'min_duration_ms' => (int) env('STOPWATCH_LOG_MIN_DURATION_MS', 50),
    'max_files' => (int) env('STOPWATCH_LOG_MAX_FILES', 200),
    'max_age_days' => (int) env('STOPWATCH_LOG_MAX_AGE_DAYS', 7),
    'detail' => env('STOPWATCH_LOG_DETAIL', 'summary'), // summary | full
    'include_bindings' => (bool) env('STOPWATCH_LOG_INCLUDE_BINDINGS', false),
    'skip_empty' => (bool) env('STOPWATCH_LOG_SKIP_EMPTY', true),
],
```

Default `min_duration_ms = 50` matches the existing `slow_threshold` default — sub-50ms requests (healthchecks, asset routes, `Inertia::lazy` skeletons) are excluded from the disk by default. Set to `0` to log everything.

`skip_empty = true` drops zero-checkpoint runs (`StopwatchMiddleware::autoStart()` produces these for any unmodified controller; with no checkpoints the markdown body has nothing to inspect).

All keys read in `ServiceProvider::configureStopwatch()` — pattern matches existing `track_queries`/`track_http` wiring (`src/ServiceProvider.php:78-88`).

---

## 4. Code structure

### 4.1 New classes

```
src/RunLog/
├── RunRecorder.php          # interface
├── MarkdownRunRecorder.php  # default impl — writes <ULID>.md
└── RunLogStore.php          # list/get/clear/prune (read side, used by commands)

src/Console/                  # new dir
├── RunsListCommand.php
├── RunsShowCommand.php
└── RunsClearCommand.php
```

### 4.2 `RunLog\RunRecorder`

```php
namespace SanderMuller\Stopwatch\RunLog;

use SanderMuller\Stopwatch\Stopwatch;

interface RunRecorder
{
    /**
     * Persist (or skip) the just-finished stopwatch run.
     *
     * Implementations MUST NOT throw. Any failure must be swallowed.
     *
     * @param array<string, scalar|null> $context request/command context (url, method, status, command, ...)
     */
    public function record(Stopwatch $stopwatch, array $context): void;
}
```

Same shape as `Notifications\StopwatchNotificationChannel` deliberately — but kept separate because:

- Notifications fire conditionally on `notify_threshold`; recorders always fire.
- Notifications carry an "alert someone" connotation; recorders carry a "persist for later" connotation.
- Different config keys, different defaults, different docs. Conflating saves ~20 LOC and costs hours of explaining-the-difference later.

### 4.3 `RunLog\MarkdownRunRecorder`

Responsibilities:

- Apply `min_duration_ms` filter — skip writes below threshold.
- Apply `skip_empty` filter — skip zero-checkpoint runs.
- Build YAML frontmatter from `$stopwatch->finalRunTotals()` (new method, §4.4) + `$context`.
- Append `$stopwatch->toMarkdown()` body.
- Append SQL/HTTP detail tables when `detail=full`. SQL detail uses the parameterised SQL text only; bindings only when `include_bindings=true`.
- Write `<ULID>.md` via `File::put($path, $contents)`. Concurrency safety comes from each request getting a unique ULID filename — there is never more than one writer for a given path. (No claim of write atomicity for arbitrary sizes.)
- Auto-create dir + `.gitignore` (containing `*.md`) on first write.
- Probabilistic age prune: `if (random_int(1, 100) <= 5) { $store->pruneByAge($maxAgeDays); }`.
- Deterministic count prune: `$store->pruneByCount($maxFiles)` after every write.

ULID generation: `(string) Str::ulid()`. Cast is required — `Str::ulid()` returns a `Symfony\Component\Uid\Ulid` object.

### 4.4 New `Stopwatch` accumulators + finalisation hook

The current implementation only snapshots query/HTTP/memory metrics **at `checkpoint()` calls** (`src/Stopwatch.php:213-232`); work between the last checkpoint and `finish()` never accumulates into checkpoint totals. For per-checkpoint display this is fine (you can always add a final checkpoint), but for run-log frontmatter it would systematically under-report.

Fix: maintain whole-run accumulators alongside the existing per-checkpoint counters.

```php
// New private fields on Stopwatch
private int $totalQueryCount = 0;
private float $totalQueryDurationMs = 0.0;
private int $totalHttpCount = 0;
private float $totalHttpDurationMs = 0.0;
private int $totalMemoryDelta = 0;
private ?int $runStartMemory = null;
```

These are incremented in the existing `QueryExecuted` / `ResponseReceived` / `ConnectionFailed` listeners alongside the per-checkpoint counters — they are never reset by `collectQueryMetrics()` / `collectHttpMetrics()`. They reset only in `reset()` (i.e. on a new run).

Memory total: capture `$this->runStartMemory = memory_get_usage()` in `reset()` when memory tracking is on; in `finish()` compute `$totalMemoryDelta = memory_get_usage() - $runStartMemory`. This avoids drifting deltas.

New public method:

```php
/**
 * Whole-run totals captured at finish() time. Includes all work since reset(),
 * not just work attributed to checkpoints. Run log uses this; toMarkdown() does not.
 *
 * @return array{
 *     duration_ms: float,
 *     checkpoints: int,
 *     queries_total: int,
 *     query_ms_total: float,
 *     http_total: int,
 *     http_ms_total: float,
 *     memory_delta_bytes: int|null,
 *     slow_threshold_ms: int,
 *     exceeds_slow_threshold: bool,
 * }
 */
public function finalRunTotals(): array
```

`toArray()` and `toMarkdown()` are deliberately NOT changed (semver). Run log consumes `finalRunTotals()` directly.

### 4.5 `Stopwatch.finish()` ordering and safety

Current `finish()` (`src/Stopwatch.php:606`) calls `dispatchNotifications()` and propagates exceptions. The revised order:

```php
public function finish(): self
{
    if (! $this->enabled || $this->startHrtime === null || $this->endHrtime !== null) {
        return $this;
    }

    $this->endTime = $this->clock->now();
    $this->endHrtime = $this->clock->hrtime();
    $this->finaliseTotals(); // closes whole-run accumulators (memory delta, etc.)

    // Recorders run BEFORE notifications. A throwing notification channel must
    // never prevent persistence of the run. Both phases swallow errors.
    $this->safeDispatchRunRecorders();
    $this->safeDispatchNotifications();

    return $this;
}
```

Both `safeDispatch*` methods wrap each handler in `try/catch`, log via `logger()->warning(...)`, and continue iterating. The existing `dispatchNotifications()` becomes the inner loop body.

**Implicit `finish()`** — `toArray()`, `toMarkdown()`, `toHtml()`, `toServerTiming()`, `toLog()`, `toStderr()`, and `dd()` all call `finish()`. Recorders fire on the **first** `finish()` only, because the second call short-circuits via `$this->endHrtime !== null`. This is intentional — code that builds the markdown twice still produces only one log file.

### 4.6 Context: persistent providers + per-run overrides

To avoid the `start()`→`reset()` race that would wipe `command` context set on `CommandStarting`, context comes from **two** sources, evaluated at `finish()` time:

```php
/** @var list<callable(Stopwatch): array<string, scalar|null>> */
private array $contextProviders = [];

/** @var array<string, scalar|null> */
private array $runContext = [];

/** Persistent — survives reset(). Used for "command", "queue job class", etc. */
public function pushRunContextProvider(callable $provider): self;

/** Per-run — cleared in reset(). Used for middleware-style "url, status". */
public function withRunContext(array $context): self;

/** Resolved at finish(). Providers first, $runContext overrides on key collision. */
public function resolveRunContext(): array;
```

Wiring:

- ServiceProvider registers a console provider once: `pushRunContextProvider(function () { return app()->runningInConsole() ? ['command' => $this->resolveCurrentCommand()] : []; })`. Provider closure uses a small private property on the provider that captures the current command name on `CommandStarting`. Provider is called at finish, so reset has no effect.
- Middleware uses `withRunContext(['url' => ..., 'method' => ..., 'status' => ...])` after `$next()` — set right before `finish()` so reset cannot interfere.
- User code can call either: persistent (`pushRunContextProvider`) for cross-cutting concerns or one-shot (`withRunContext`) for per-route hints.

`reset()` clears `$runContext` only. `$contextProviders` persists (it is wiring, not state).

### 4.7 `recordRunsTo()` semantics: replace, not append

```php
/**
 * Replace the current set of recorders. Pass an empty array to disable.
 *
 * Variadic `recordRunsTo($a, $b)` is equivalent to passing `[$a, $b]`.
 *
 * @param list<RunRecorder>|RunRecorder ...$recorders
 */
public function recordRunsTo(RunRecorder ...$recorders): self
{
    $this->runRecorders = $recorders;
    return $this;
}
```

Replace semantics match `notifyUsing()` (`src/Stopwatch.php:763-767`). Appending was easy to misuse on a singleton — repeated provider boots would double-write.

### 4.8 ServiceProvider wiring

In `configureStopwatch()` (`src/ServiceProvider.php:51`):

```php
if (($config['run_log']['enabled'] ?? false) === true && $this->canPersistRunLogs()) {
    $stopwatch->recordRunsTo($this->app->make(MarkdownRunRecorder::class));
    $stopwatch->pushRunContextProvider($this->app->make(ConsoleCommandContextProvider::class));
}
```

`canPersistRunLogs()` returns `false` (with a one-time `logger()->warning()`) when `storage_path()` is unavailable — i.e. running outside a Laravel application context.

`ConsoleCommandContextProvider` is a tiny invokable class that listens for `CommandStarting` (subscribed in its constructor) and returns `['command' => $this->command]` when called. Implemented as a class, not a closure, so the test harness can spy on it.

Bind `RunLogStore` and `MarkdownRunRecorder` as singletons in `packageRegistered()` so the recorder, store, and commands all share state.

Register the three commands via `->hasCommands([...])` in `configurePackage()` (Spatie convention).

### 4.9 Middleware: populate request context, survive exceptions

`StopwatchMiddleware::handle()` (`src/StopwatchMiddleware.php:22`) becomes:

```php
public function handle(Request $request, Closure $next, string ...$options): Response
{
    $autoStart = in_array(self::AUTOSTART, $options, true);

    if ($autoStart && $this->stopwatch->enabled() && ! $this->stopwatch->started()) {
        $this->stopwatch->start();
    }

    try {
        /** @var Response $response */
        $response = $next($request);
    } catch (\Throwable $e) {
        if ($this->stopwatch->enabled() && $this->stopwatch->started()) {
            $this->stopwatch->withRunContext([
                'url' => $this->stripQuery($request->fullUrl()),
                'method' => $request->method(),
                'status' => 500, // best-effort; the framework's exception handler decides the real status
                'threw' => true,
            ]);
            $this->stopwatch->finish();
        }
        throw $e;
    }

    if ($this->stopwatch->enabled() && $this->stopwatch->started()) {
        $this->stopwatch->withRunContext([
            'url' => $this->stripQuery($request->fullUrl()),
            'method' => $request->method(),
            'status' => $response->getStatusCode(),
        ]);
        $this->stopwatch->finish();
        $response->headers->set('Server-Timing', $this->stopwatch->toServerTiming());
    }

    return $response;
}

private function stripQuery(string $url): string
{
    $pos = strpos($url, '?');
    return $pos === false ? $url : substr($url, 0, $pos);
}
```

- `try/finally` would be cleaner but we need to set context before finish AND we need to rethrow — the explicit `try/catch + throw` reads more obviously.
- The 1-line `stripQuery` is duplicated from `Stopwatch::stripUrlQueryString`. Keeping the latter private — promoting it to public-static would lock a one-line helper into the public API for no real reuse benefit.
- New context key `threw` is `true` only on exception path; consumed by run log / future filters.

---

## 5. CLI commands

All three live under `src/Console/`.

### 5.1 `stopwatch:runs:list`

```
$ php artisan stopwatch:runs:list --limit=10

  Recent stopwatch runs

  ID                          Duration  URL / Command         Status  Recorded
  01HZ8K9X4N5P2Q3R4S5T6U7V8W  487ms     GET /admin/users      200     2 min ago
  01HZ8K9YA1B2C3D4E5F6G7H8I9  312ms     GET /api/products     200     5 min ago
  01HZ8KA0B2C3D4E5F6G7H8I9JK  8.4s      artisan app:reindex   -       1 hr ago

  Run php artisan stopwatch:runs:show <id> to inspect.
```

Flags:

- `--limit=N` (default 30)
- `--sort=duration|recorded` (default `duration`)
- `--slow` — only runs that exceeded `slow_threshold_ms`
- `--threw` — only runs whose context contains `threw=true`

### 5.2 `stopwatch:runs:show <id>`

`$this->line(File::get($path))`. Plain markdown output is already legible in terminals and is what the skill consumes.

### 5.3 `stopwatch:runs:clear`

Flags:

- (no flags) — wipe everything, prompt for confirmation
- `--keep=N` — keep last N
- `--days=N` — keep last N days
- `--force` / `-f` — skip confirmation

---

## 6. Skill integration

Extend `resources/boost/skills/profile-app/SKILL.md` with a new section after **Step 5** ("Catch slow paths in production"):

```markdown
### 6. Browse-and-debug from a run log

When you can't easily wire `stopwatch()->render()` into the page (e.g. JSON API,
SPA backend, reproducing a bug across many requests), turn on the run log:

    STOPWATCH_LOG_RUNS=true

Then ask the user to reproduce the slow path in their browser or CLI. Each
finished run writes `storage/stopwatch/runs/<ULID>.md`.

Inspect from the AI side:

    php artisan stopwatch:runs:list --slow --limit=10
    php artisan stopwatch:runs:show <id>

The `show` output is markdown — drop it directly into your reasoning. Sort by
duration to find the worst offenders, then:

- One row dominating delta-share → hot spot.
- High `q` count on one row → N+1 candidate; flip to `STOPWATCH_LOG_DETAIL=full`
  to see the actual SQL.
- Frontmatter `queries_total` >> sum of per-checkpoint queries → significant
  work happens after the last checkpoint; add a checkpoint near the response
  return and re-profile.
- High `h` count → outbound API loop; same — `full` shows method/URL/status
  per call.
- `threw: true` in frontmatter → request crashed; the profile shows where the
  time went up to the crash point.

Clean up after debugging:

    php artisan stopwatch:runs:clear
```

Keep the existing skill's structure; do not introduce a new top-level skill — the activation triggers ("slow request", "where is the time going") are identical, only the data source differs.

---

## 7. Privacy & overhead

| Concern               | Mitigation                                                              |
|-----------------------|-------------------------------------------------------------------------|
| URLs leak api tokens  | Middleware `stripQuery()` (mirrors `src/Stopwatch.php:467`)             |
| SQL bindings leak PII | Bindings persisted only when `STOPWATCH_LOG_INCLUDE_BINDINGS=true`. Default off in summary AND full modes. Documented as an explicit PII opt-in |
| Disk usage            | `max_files=200` deterministic cap + 5% probabilistic age prune (`max_age_days=7`) |
| Per-run overhead      | One `file_put_contents` per qualifying request when enabled. Skipped entirely when `enabled=false`, when `duration < min_duration_ms`, or when `skip_empty=true` and `checkpoints=0` |
| Empty-run noise       | `skip_empty=true` (default) drops zero-checkpoint autostart runs        |
| Recorder failure      | Caught + logged; never propagates to the request                        |
| Notification failure  | Now also caught (was previously propagating); never blocks recorders    |
| Stopwatch singleton   | Run-log feature is **explicitly Octane/Swoole-unsupported in v1**. Documented in §8 |

---

## 8. Non-goals

- **Octane / Swoole** — Stopwatch is registered as a singleton with mutable per-run state. Concurrent coroutines already trample each other (a pre-existing latent issue); the run log feature does not introduce Octane support and must not be enabled under Octane until the Stopwatch lifecycle is per-request. Adding a `RunLog/Octane` mode is a follow-up.
- **Flame chart / call tree visualisation** — Stopwatch is flat, the markdown table is the entire shape.
- **Web UI / debug bar panel** — out of scope. Existing `DebugbarCollector` shows the live timeline; the run log is for *post-hoc* skill consumption.
- **Remote sinks (S3, Loki, etc.)** — possible later via a custom `RunRecorder` impl.
- **Sampling beyond `min_duration_ms`** — no rate-limiting, no per-route gates. Custom `RunRecorder` impls can filter via `record()`.
- **Replacing notifications** — `notify_threshold` + channels stay exactly as today. Run log is additive.
- **Promoting `Stopwatch::stripUrlQueryString` to public-static** — kept private; middleware duplicates the one-liner.

---

## Implementation

### Phase 1: Foundation — recorder interface, store, markdown writer (Priority: HIGH)

- [ ] Create `src/RunLog/RunRecorder.php` interface — `record(Stopwatch $stopwatch, array $context): void`. Doc explicitly: implementations must not throw.
- [ ] Create `src/RunLog/RunLogStore.php` with `listRuns()`, `getRun()`, `getRunPath()`, `clear()`, `pruneByCount()`, `pruneByAge()`. Path resolution: `STOPWATCH_LOG_DIR` env → `storage_path('stopwatch/runs')`. When neither resolves (no Laravel), the store reports unavailable; recorder caller skips persistence with a one-time logger warning.
- [ ] Implement tiny line-based YAML frontmatter parser inside `RunLogStore` per §2.2 — split on first `:`, plain string values, no nesting. ~30 LOC.
- [ ] Auto-create dir + `.gitignore` (`*.md`) on first write.
- [ ] Create `src/RunLog/MarkdownRunRecorder.php` — applies `min_duration_ms` and `skip_empty` filters, builds frontmatter from `Stopwatch::finalRunTotals()` + `$context`, appends `Stopwatch::toMarkdown()` body, optional SQL/HTTP detail when `detail=full`, optional bindings when `include_bindings=true`. Write `<ULID>.md` via `(string) Str::ulid()` filename, deterministic count-prune after every write, 5% probabilistic age-prune.
- [ ] Tests — `RunLogStoreTest`: write/list/get/clear, count prune, age prune, `.gitignore` auto-create, frontmatter parse round-trip, parser handles `command: cache:clear` (colon in value), unavailable store path. `MarkdownRunRecorderTest`: frontmatter shape, body shape, `min_duration_ms` filter, `skip_empty` filter, summary vs full detail, bindings excluded by default, bindings included when flag set, ULID uniqueness under 100 sequential writes.

### Phase 2: Stopwatch whole-run accumulators + finalisation (Priority: HIGH)

- [ ] Add `$totalQueryCount`, `$totalQueryDurationMs`, `$totalHttpCount`, `$totalHttpDurationMs`, `$totalMemoryDelta`, `$runStartMemory` private fields.
- [ ] In the `QueryExecuted` listener (`src/Stopwatch.php:324-339`) increment whole-run counters alongside per-checkpoint counters.
- [ ] In `recordHttpCall()` (`src/Stopwatch.php:474`) increment whole-run counters alongside per-checkpoint counters.
- [ ] Capture `$runStartMemory` in `reset()` when memory tracking is on; compute `$totalMemoryDelta` in new private `finaliseTotals()` called from `finish()`.
- [ ] Reset all whole-run counters in `reset()`.
- [ ] Add public `finalRunTotals(): array` returning the shape in §4.4. **Do not modify `toArray()` or `toMarkdown()`.**
- [ ] Tests — `StopwatchTest` additions: query/http/memory work between the last checkpoint and `finish()` is reflected in `finalRunTotals()` but NOT in `toMarkdown()` body (current behaviour preserved); accumulators reset on `reset()`; tracking-disabled runs report null/zero.

### Phase 3: Stopwatch run-log integration — recorders + context (Priority: HIGH)

- [ ] Add `$runRecorders`, `$runContext`, `$contextProviders` properties.
- [ ] Add `recordRunsTo(RunRecorder ...$recorders): self` — **replace** semantics, not append.
- [ ] Add `withRunContext(array $context): self` — merges into per-run context.
- [ ] Add `pushRunContextProvider(callable $provider): self` — appends to provider list (these are wiring, append is correct).
- [ ] Add `resolveRunContext(): array` — runs providers, then merges `$runContext` overrides on top.
- [ ] Add private `safeDispatchRunRecorders()` and `safeDispatchNotifications()` — both wrap each handler in `try/catch` + `logger()->warning()`.
- [ ] Modify `finish()`: call `finaliseTotals()`, then `safeDispatchRunRecorders()`, then `safeDispatchNotifications()` (recorders BEFORE notifications, both protected).
- [ ] In `reset()`: clear `$runContext` (do NOT clear `$runRecorders` or `$contextProviders`).
- [ ] Tests — recorders fire on first `finish()` only, throwing notification does not abort recorders, throwing recorder is caught, `recordRunsTo()` replaces existing list, providers survive `reset()`, `withRunContext` cleared on `reset()`, `withRunContext` overrides provider on key collision, recorders fire on every implicit `finish()` trigger (`toArray`, `toMarkdown`, `toHtml`, `toServerTiming`, `dd()`) but only once because of `$endHrtime` short-circuit.

### Phase 4: Configuration + service provider wiring (Priority: HIGH)

- [ ] Add `run_log` block to `config/stopwatch.php` per §3.
- [ ] Bind `RunLogStore` and `MarkdownRunRecorder` as singletons in `ServiceProvider::packageRegistered()`. Recorder constructor reads config keys at build time (not at every record call).
- [ ] Implement `ConsoleCommandContextProvider` — invokable class, listens to `CommandStarting`, returns `['command' => $name]` when invoked. Bind in container.
- [ ] In `configureStopwatch()`, when `run_log.enabled=true` AND `storage_path()` is available: `recordRunsTo($recorder)` and `pushRunContextProvider($provider)`.
- [ ] When `run_log.enabled=true` but `storage_path()` unavailable: log a one-time warning, skip wiring.
- [ ] Tests — `ServiceProviderTest`: recorder registered when `run_log.enabled=true`, not when false, not when storage unavailable; `command` context populated under console even after `start()`/`reset()` because provider is evaluated at `finish()`.

### Phase 5: Middleware context + exception path (Priority: HIGH)

- [ ] In `StopwatchMiddleware::handle()`, wrap `$next($request)` in try/catch. On the success path, set `withRunContext([url, method, status])` after `$next()` and call `finish()`. On the exception path, set `withRunContext([url, method, status:500, threw:true])`, call `finish()`, then re-throw.
- [ ] Inline `stripQuery()` private helper in the middleware (1-liner). Do NOT promote `Stopwatch::stripUrlQueryString`.
- [ ] Tests — context populated on success, query string stripped, status reflects response code, on `$next()` throw the run log is written with `threw:true` and the exception still propagates, no context written when stopwatch disabled.

### Phase 6: Artisan commands (Priority: HIGH)

- [ ] `src/Console/RunsListCommand.php` — signature `stopwatch:runs:list {--limit=30} {--sort=duration} {--slow} {--threw}`. Render via `$this->table()`. Format `duration_ms` via `Stopwatch::formatDuration()`.
- [ ] `src/Console/RunsShowCommand.php` — signature `stopwatch:runs:show {id}`. Output the file contents directly. Returns failure when id missing.
- [ ] `src/Console/RunsClearCommand.php` — signature `stopwatch:runs:clear {--keep=} {--days=} {--force}`. Confirm before destructive ops unless `--force` (or `--no-interaction`).
- [ ] Register all three via `->hasCommands([...])` in `configurePackage()`.
- [ ] Tests — testbench-driven: list filters (`--slow`, `--threw`, `--sort`), show prints body, show on missing id returns failure, clear with `--keep`, clear with `--days`, clear without confirmation aborts.

### Phase 7: Skill update (Priority: MEDIUM)

- [ ] Add **Step 6 — Browse-and-debug from a run log** to `resources/boost/skills/profile-app/SKILL.md` (placement and content per spec §6).
- [ ] Verify activation cues unchanged.

### Phase 8: Docs + release notes (Priority: MEDIUM)

- [ ] Add a "Run log" section to `README.md` between the "Server-Timing" and "Notifications" sections (verify those headings exist before placement).
- [ ] Document env vars, file location, default behavior, the three artisan commands, the Octane v1-unsupported note, and the explicit `STOPWATCH_LOG_INCLUDE_BINDINGS` PII warning.
- [ ] Write `RELEASE_NOTES_<next-version>.md`. Do not hand-edit `CHANGELOG.md` (per `CLAUDE.md`).
- [ ] Run pre-release verification: `vendor/bin/pint --dirty --format agent`, `vendor/bin/rector process`, `vendor/bin/phpstan analyse --memory-limit=2G`, `vendor/bin/pest`. Each must show 0 issues.
- [ ] If the new methods on `Stopwatch` push cognitive complexity past the existing `@phpstan-ignore complexity.classLike` budget, extract `safeDispatchRunRecorders` / `finaliseTotals` into a small `RunLog/StopwatchRunLogger` collaborator rather than bumping the baseline.

### Phase 9: Long-tail polish (Priority: LOW)

- [ ] `--format=json` flag on `stopwatch:runs:list` for scripting.
- [ ] Memoise frontmatter parses in `RunLogStore::listRuns()` for high `max_files` values.
- [ ] Deterministic `StaleRunPruneCommand` for users who dislike probabilistic age prune.
- [ ] Integration test: write 1000 files, verify `listRuns(30)` reads only first ~1KB per file (bounded I/O).

---

## Open Questions

1. **Should `command` context capture `$argv` arguments (not just the command name)?** Useful for distinguishing `app:reindex --tenant=acme` vs `app:reindex --tenant=other`, but argv often contains secrets/tokens for one-off scripts. Default: name only. Decision needed before Phase 4.

2. **Should the `threw: true` context propagate to log/notification channels too, or stay run-log-only?** Currently other channels do not receive context. Leaning run-log-only — channels read `Stopwatch` directly and have no shape for context. Out of scope unless requested.

3. **Should `RunRecorder` ever receive a *queued* dispatch (push to a queued job that writes the file) for very-high-traffic apps?** Out of scope for v1; document as a follow-up. The default sync recorder is so cheap (~1 file write) that queueing adds more latency than it saves.

4. **Queue / job context provider** — analogous to `ConsoleCommandContextProvider`, listening to `JobProcessing` to set `['job' => Job::class]`. Should this ship in v1 or wait? Leaning **wait** — adds another wiring path and the run-log middleware path doesn't cover queue jobs anyway (no autoStart hook for jobs). Phase 9 candidate.

---

## Resolved Questions

1. **Should `MarkdownRunRecorder` log every run, or only slow ones?** **Decision:** default `min_duration_ms = 50` (matches existing `slow_threshold` default). Set to `0` to log everything. **Rationale:** every healthcheck/asset request writing a file = dev-disk noise; the threshold matches what users already think of as "slow" in this package.

2. **Should `RunRecorder` live under `Notifications/` or a new `RunLog/` namespace?** **Decision:** new `RunLog/` namespace. **Rationale:** different semantics (persist vs alert), different defaults, different config; conflating them costs more in long-term explanation than the ~20 LOC saved.

3. **Should `Stopwatch::dd()` write a run-log file?** **Decision:** yes — `dd()` calls `finish()`, which dispatches recorders. **Rationale:** if you crash-stopped a slow path, you want the file. The recorder fires before the process exits.

4. **Should context fire on `start()` or on `finish()`?** **Decision:** providers evaluated at `finish()`; one-shot `withRunContext` set anywhere, cleared on `reset()`. **Rationale:** evaluating at finish dodges the `start()`→`reset()` race entirely and lets providers read post-run state if they want to.

5. **Octane / Swoole support?** **Decision:** explicitly unsupported in v1; documented in §8. **Rationale:** the Stopwatch singleton already trampling state across coroutines is a pre-existing issue; the run log feature should not pretend to fix it. Per-request lifecycle is a separate, larger refactor.

6. **Promote `Stopwatch::stripUrlQueryString` to `public static`?** **Decision:** no — keep private, duplicate the one-liner in middleware. **Rationale:** locking a one-line helper into the public API is a semver commitment with no real reuse benefit.

7. **`recordRunsTo()` — append or replace?** **Decision:** replace, matching `notifyUsing()`. **Rationale:** singletons + repeat boots = duplicate writes. Replace is the safer default; users can always read+append manually.

8. **Per-checkpoint metric snapshot vs whole-run totals — fix or document?** **Decision:** fix via new whole-run accumulators (§4.4). `finalRunTotals()` is the source of truth for run-log frontmatter. `toMarkdown()` body unchanged. **Rationale:** under-reporting totals would make the feature subtly misleading; the fix is small, additive, and does not change existing public output.

9. **Empty (zero-checkpoint) runs?** **Decision:** drop them by default (`skip_empty=true`). **Rationale:** middleware autoStart produces these for any unmodified controller; with no checkpoints there is nothing to debug.

10. **Crashed requests?** **Decision:** middleware writes the run log on the exception path with `threw: true`, then re-throws. **Rationale:** slow-then-crashing requests are exactly the ones you want logged; losing them would defeat the feature for crash-debugging.

11. **Frontmatter format — YAML lib or hand-rolled?** **Decision:** hand-rolled, ~30 LOC, fixed shape per §2.2. **Rationale:** dependency-free; the shape is rigid and writer-controlled, so the parser cannot encounter weird YAML.

12. **Non-Laravel host fallback?** **Decision:** dropped. Run log is Laravel-only in v1. **Rationale:** the feature already depends on `app()`, `Str::ulid()`, `File`, artisan, service provider, middleware. A `/tmp` fallback is fiction.

13. **`Str::ulid()` cast?** **Decision:** explicit `(string)` cast in writer. **Rationale:** `Str::ulid()` returns `Ulid` object, not a string; filename concatenation needs `__toString()`.

---

## Findings

<!-- Notes added during implementation. Do not remove this section. -->
