# Debugging with probes

A checkpoint records a label, the values you pass, and the time since the previous one. That makes it a structured `dump()`: put a few on the path you suspect, run the code once, and read the values back in execution order. Debug mode writes each one to disk the moment it happens, so this works in a test, a command, a queued job or a request, and survives an exception, `exit()` or `dd()`.

```dotenv
STOPWATCH_DEBUG=true
```

Debug mode only switches on when `APP_DEBUG=true` or `APP_ENV` is `local` or `testing`, and never under Octane. It forces the [run log](09-run-log.md) on with no minimum duration and gives every artisan command and queued job a run of its own.

## Add probes

```php
stopwatch()->probe('repeat-customer branch', ['rate' => $rate, 'eligible' => $eligible]);
```

`probe()` records exactly like `checkpoint()` and also stores the `file:line` it was called from. The flag marks the line as temporary, so you can find it later. Always write it as `stopwatch()->probe(`: that exact text is what you search for at the end, and a bare `->probe(` also matches unrelated `probe()` methods in your app.

Pass scalars or small arrays (`$order->id`, not the whole `$order`). Each value is capped at 4096 bytes in the stream (`STOPWATCH_DEBUG_VALUE_MAX_BYTES`), and a run keeps 1000 records (`STOPWATCH_DEBUG_MAX_RECORDS`). Metadata is written to disk as you pass it, so keep secrets out of it.

## Read the run

Run the test, the command or the request, then:

```bash
php artisan stopwatch:runs:show latest
```

```markdown
# Stopwatch run (shutdown)

- **Run:** 01K6Q2M8D4A9XJ3W7N5T1B6F0R
- **Started:** 2026-10-05T14:11:56.061+00:00
- **Checkpoints:** 3 of 3
- **End:** shutdown after 3.1ms

| # | Checkpoint | Probe | Δ | Cumulative | Queries | HTTP | Location | Metadata |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | cart loaded | yes | 0ms | 0ms |  |  | tests/Feature/CheckoutTest.php:41 | {"items":3,"customer_orders":4} |
| 2 | repeat-customer branch | yes | 2.4ms | 2.4ms |  |  | app/Pricing/DiscountCalculator.php:58 | {"rate":0.1,"eligible":false} |
| 3 | total | yes | 0.3ms | 2.7ms |  |  | app/Pricing/DiscountCalculator.php:73 | {"total":120} |
```

Rows are in execution order. A probe that is missing means its code did not run. `--format=json` prints the raw records with the values unescaped. Query and HTTP counts per probe need `STOPWATCH_TRACK_QUERIES` / `STOPWATCH_TRACK_HTTP`.

The state in the heading, and in the **State** column of `stopwatch:runs:list`:

| State | Meaning |
|---|---|
| `finished` | `finish()` ran; the markdown run file exists too |
| `shutdown` | the process ended without `finish()`, as in a test, or through `exit()` / `dd()` / an uncaught error |
| `restarted` | `start()` was called while the run was active |
| `unfinished` | no end record: memory or time limit, a killed process, or still running |

If nothing was recorded, `stopwatch:runs:show latest` says why, also when the blocked run happened in another process, for example a test suite that runs as `APP_ENV=staging`.

## Check the fix

Keep the probes in place, reproduce again, and compare the two runs:

```bash
php artisan stopwatch:runs:diff 01K6Q2M8D4A9XJ3W7N5T1B6F0R latest
```

Per probe it shows the time change, the query and HTTP counts, the metadata values that changed (`eligible: false -> true`), and the probes that ran in one run only. Both runs need debug mode, because the diff reads the stream.

## Remove the probes

```bash
grep -rn --include='*.php' --exclude-dir=vendor -F 'stopwatch()->probe(' .
```

To make a forgotten probe fail CI, include the shipped PHPStan rule. It reports every `probe()` call on a `Stopwatch`, including a nullable one, with the identifier `stopwatch.probe`:

```neon
includes:
    - vendor/sandermuller/stopwatch/resources/phpstan/no-probes.neon
```

Then turn `STOPWATCH_DEBUG` off and run `php artisan stopwatch:runs:clear --force`.

## Notes

- The location is the first frame outside the package. A helper of your own that wraps `stopwatch()` shows up as that helper; a checkpoint in a Blade view points at the compiled view.
- `php artisan test` runs PHPUnit in a child process. Its own empty command run is dropped, so `latest` is the test's run.
- Inside PHPUnit, Laravel does not fire command events, so a command run through `$this->artisan()` is not finished on its own. Its probes still land in the stream and end as `shutdown`.
- An agent with the [`stopwatch-debug` skill](13-ai-assistant.md) runs this loop on its own, and the same reads are available as [MCP tools](13-ai-assistant.md#mcp).
