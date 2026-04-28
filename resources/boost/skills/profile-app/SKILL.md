---
name: profile-app
description: "Profile a slow request, command, or code path with sandermuller/stopwatch. Activate when the user mentions: slow request, slow endpoint, slow page, slow query, slow job, slow command, slow API call, slow HTTP, performance issue, profile this, why is this slow, where is the time going, optimize, bottleneck, latency, n+1, query count, memory usage, http calls, outbound requests, server-timing."
---

# Profile a slow code path

Use `sandermuller/stopwatch` to find where time is spent in a request, command, or piece of code. Output is a self-contained HTML card you can drop into a Blade view, an email, a `Server-Timing` header, the log, or copy as Markdown to paste into a chat.

## When to Use This Skill

Activate when the user reports a real performance question — a slow endpoint, a long-running command, an N+1 suspicion, a memory spike — or asks "where is the time going?" / "why is this slow?".

Do NOT activate for:
- Micro-benchmarks of a pure function (use a real benchmark tool like PHPBench)
- Production load tests (use k6, Locust, etc.)
- Profiling already-instrumented code that has its own metrics

## Decision: which entry point

| Situation | Reach for |
|-----------|-----------|
| HTTP request feels slow, no idea where | `StopwatchMiddleware` + `Server-Timing` header → read in DevTools Network tab |
| HTTP request slow, want a visual report | `StopwatchMiddleware::autoStart()` + render `stopwatch()` in a debug Blade partial |
| Long artisan command | `stopwatch()->checkpoint(...)` around suspect blocks, then `stopwatch()->toLog('Profile:')` at the end |
| One specific block | `stopwatch()->measure('label', fn () => ...)` |
| Catch only the slow ones in production | `stopwatch()->notifyIfSlowerThan(500)` + `MailChannel` or custom channel |
| Need to share a profile with a teammate or AI | `stopwatch()->toMarkdown()` (or click the clipboard icon in the rendered HTML) |

## Workflow

### 1. Start the stopwatch

The first call to `checkpoint()` auto-starts. For middleware-driven profiling there is nothing to wire — `StopwatchMiddleware::autoStart()` starts on every request.

```php
stopwatch()->withQueryTracking()->withMemoryTracking()->withHttpTracking()->start();
```

Enable `withQueryTracking()` whenever the suspect path touches the database — checkpoints will show query count + duration. Enable `withMemoryTracking()` when memory could be the issue (large collections, image processing). Enable `withHttpTracking()` when the path makes outbound API calls — checkpoints will show count + total time + per-call previews (method/URL/status). HTTP tracking only catches calls through Laravel's `Http::` facade; direct `new GuzzleHttp\Client` calls bypass it. All three are off by default to keep overhead near zero.

### 2. Place checkpoints at decision boundaries

A checkpoint marks "this section ended; everything since the last checkpoint counts toward this label." Put them where you would log "step done":

```php
stopwatch()->checkpoint('Validated input');
$user = User::with('orders')->find($id);
stopwatch()->checkpoint('Loaded user', ['id' => $id]);
$pdf = Pdf::loadView(...)->output();
stopwatch()->checkpoint('Rendered PDF');
Storage::put($path, $pdf);
stopwatch()->checkpoint('Uploaded');
```

**Sizing rules:**
- ≤ 3 checkpoints → too coarse, you'll learn "it's slow" but not where.
- 4–12 checkpoints → sweet spot for a single request or command.
- 20+ checkpoints → too noisy; drop the obviously-fast ones and re-profile.

Attach metadata that you'd want to see in the report (`['rows' => 4200]`, `['cache' => 'miss']`). It renders inline as chips on each row.

### 3. Render and read the output

```php
// In a Blade view (debug partial, dev-only route, etc.)
{!! stopwatch()->render() !!}
// or
@stopwatch
```

Reading the card:
- **Overview bar** at the top — proportional segments per checkpoint. The widest segment is your hot spot.
- **Per-row bar + share %** — cross-highlights the matching segment on hover/focus.
- **Slow tier (light/medium/heavy red)** — anything above the slow threshold (default 50ms, override via `STOPWATCH_SLOW_THRESHOLD`) gets a tiered red signal.
- **Hover tooltip per row** — full label, timestamp, cumulative time, share, queries, memory.
- **Footer totals** — cumulative queries, query time, memory delta when tracking is enabled.
- **Clipboard icon (header)** — copies a Markdown summary table; paste into a chat with an AI, a bug report, or a Slack thread without screenshots.

Other surfaces:

```php
stopwatch()->toLog('Profile:');                       // log writer
stopwatch()->toStderr('Profile:');                    // stderr (CLI)
stopwatch()->toServerTiming();                        // Server-Timing header
stopwatch()->toMarkdown();                            // Markdown table (programmatic)
stopwatch()->toArray();                               // structured data
```

### 4. Iterate

Profile → identify hot row → fix or break it down further → re-profile. Common patterns:

- **One row dominates with high query count** → N+1; eager-load (`with(...)`), batch, or cache.
- **One row dominates with high HTTP count** → outbound API in a loop; batch the upstream call, cache responses, or push the work to a queued job.
- **Memory spike on one row** → streaming opportunity (`chunk`, `cursor`, `LazyCollection`).
- **Many small rows are individually fast but add up** → consider parallelism (queued jobs, `Octane::concurrently`).
- **One row is fast but its delta-since-last is huge** → the time is actually between checkpoints; add a checkpoint inside.

### 5. Catch slow paths in production

For requests that only sometimes go slow, set a threshold:

```php
// AppServiceProvider::boot()
stopwatch()->notifyIfSlowerThan(500);
```

Or via env: `STOPWATCH_NOTIFY_THRESHOLD=500`. Pair with `StopwatchMiddleware` and you get an alert (log, email, or custom channel) every time a request crosses the threshold, with the full HTML report attached.

Channels are configured in `config/stopwatch.php`:

```php
'notification_channels' => [
    \SanderMuller\Stopwatch\Notifications\LogChannel::class,
    \SanderMuller\Stopwatch\Notifications\MailChannel::class,
],
```

Implement `StopwatchNotificationChannel` for Slack, PagerDuty, etc.

## Guidelines

- **Keep it off when not in use.** Set `STOPWATCH_ENABLED=false` in production unless you've intentionally wired the middleware or notifications. Disabled mode makes every call a near-zero no-op.
- **Don't ship checkpoint calls inside hot loops** that run thousands of times per request — they're cheap but not free, and the report becomes unreadable.
- **Query/memory tracking has overhead.** Don't enable globally in production; gate behind config or a debug flag.
- **The rendered card is email-safe and self-contained.** All styles inline with hex fallbacks, JS-optional features (theme toggle, copy button) hide gracefully when JS is stripped.

## Quick cheat sheet

```php
// One-shot measure
$result = stopwatch()->measure('Heavy work', fn () => doIt());

// Multi-step
stopwatch()->withQueryTracking()->withHttpTracking()->start();
stopwatch()->checkpoint('Step 1');
stopwatch()->checkpoint('Step 2', ['rows' => 42]);
echo stopwatch()->render();

// Production tripwire
stopwatch()->notifyIfSlowerThan(500);

// Share the result
echo stopwatch()->toMarkdown();
```
