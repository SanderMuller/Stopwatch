# Checkpoints

```php
stopwatch()->checkpoint('First checkpoint');
stopwatch()->checkpoint('Second checkpoint');
stopwatch()->lap('Third checkpoint'); // alias for checkpoint()
```

`checkpoint()` auto-starts the stopwatch. Call `start()` explicitly to begin a fresh measurement; it resets any checkpoints already recorded.

Attach metadata to any checkpoint:

```php
stopwatch()->checkpoint('Query executed', ['table' => 'users', 'rows' => 42]);
```

## Measure a closure

```php
$result = stopwatch()->measure('Heavy computation', function () {
    return doExpensiveWork();
});
```

The checkpoint is recorded after the closure returns, and its return value passes through.

## Where checkpoints go

By default a checkpoint is collected and rendered later. `outputTo()` changes that:

```php
use SanderMuller\Stopwatch\StopwatchOutput;

stopwatch()->outputTo(StopwatchOutput::Log)->start();

stopwatch()->checkpoint('First checkpoint'); // logged as it happens
```

| Mode | Effect |
|---|---|
| `StopwatchOutput::Silent` | collect only, render later (default) |
| `StopwatchOutput::Log` | Laravel log |
| `StopwatchOutput::Stderr` | stderr |
| `StopwatchOutput::Dump` | Laravel's `dump()` |

Override per checkpoint, or send a single one to the log:

```php
stopwatch()->checkpoint('Debug this', output: StopwatchOutput::Dump);

stopwatch()->log('Query executed');
stopwatch()->log('Query executed', level: 'warning');
```

## Write the whole profile

```php
stopwatch()->toStderr('Profile:');
stopwatch()->toLog('Profile:', level: 'info');
```
