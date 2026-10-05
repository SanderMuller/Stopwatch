# API reference

## Lifecycle

`checkpoint()` auto-starts. `start()` resets and begins a fresh measurement. Rendering (`render()`, `toArray()`, `toStderr()`, `toLog()`) finishes the run implicitly. `stop()` freezes the timing early:

```php
stopwatch()->checkpoint('First checkpoint');
stopwatch()->stop();

// work you do not want measured

{{ stopwatch()->render() }}
```

`stopwatch()->toString()` returns the total as a string (`"116ms"`).

## Probes and run ids

```php
stopwatch()->probe('Loaded user', ['id' => $user->id]); // a checkpoint flagged as temporary
stopwatch()->runId();                                   // ULID of the active or last run
```

`probe()` records exactly like `checkpoint()`; the flag marks it for removal and for the opt-in PHPStan rule. `runId()` is the file name of the run's markdown and JSONL files; it needs `symfony/uid`, which `laravel/framework` ships. Each entry of `toArray()['checkpoints']` carries `probe` and `location` (`path:line`).

## Enable and disable at runtime

```php
stopwatch()->disable();
stopwatch()->checkpoint('Skipped'); // no-op
stopwatch()->enable();
```

Disabled calls are no-ops with near-zero overhead, the same switch as `STOPWATCH_ENABLED=false`.

## Serialization

```php
$data = stopwatch()->toArray();
$json = stopwatch()->toJson();
$md   = stopwatch()->toMarkdown();
```

## Debugging

```php
stopwatch()->dump(); // dump the instance
stopwatch()->dd();   // dump and die
```

`dd()` finishes the run before inspecting its arguments, so `dd($exception)` does not capture the exception; see the [run-log limitations](09-run-log.md#limitations).
