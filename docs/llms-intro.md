# Stopwatch

> A profiler and debugging aid for PHP and Laravel, distributed as the Composer package `sandermuller/stopwatch`. Add checkpoints to a request, command or job, read the time between them, and attach values plus query, memory and outbound-HTTP metrics. With debug mode on, every checkpoint is written to disk as it happens, so an agent can add `probe()` calls, reproduce, and read `stopwatch:runs:show latest`. It looks at the run in front of you; it aggregates nothing across requests.

Key properties an agent should know before reaching for it:

- **Not an APM.** No cross-request aggregation, no service map, no trend alerting. For "which segment of this request is slow", it is the right tool; for "has p95 regressed this week", it is not.
- **Off is free.** `STOPWATCH_ENABLED=false`, or `stopwatch()->disable()`, makes every call a no-op. Leaving instrumentation in shipped code is intended.
- **Debug mode is opt-in and guarded.** `STOPWATCH_DEBUG=true` streams each checkpoint to `<ULID>.jsonl` and forces the run log on, but only when `APP_DEBUG=true` or `APP_ENV` is `local`/`testing`, and never under Octane; a blocked process leaves a note, so `stopwatch:runs:list` and `stopwatch:runs:show latest` say why. Metadata is written as passed. Write probes as `stopwatch()->probe(` and remove them afterwards.
- **The run log is opt-in and Laravel-only.** `STOPWATCH_LOG_RUNS=true` turns it on, it is unsupported under Octane and Swoole, and runs faster than 50ms are skipped by default. Never assume a run was recorded.
- **The toolbar is default-deny by environment.** Its panel exposes raw SQL with bound values, so `STOPWATCH_INJECT_ENVIRONMENTS` defaults to `local` alone. Do not widen it to a rule like "not production" for an internet-reachable environment.
- **HTTP tracking sees Laravel's `Http::` facade only.** A direct Guzzle client bypasses the event dispatcher and is invisible, the same limitation Telescope has.
- **PII is opt-in, in both directions.** SQL bindings and exception messages are excluded from the run log until explicitly enabled; stack-trace `args` are never persisted, and hidden context is never read.
