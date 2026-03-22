# Upgrading from Stopwatch 0.3.x to 0.4.x

## Breaking changes

### `finish()` no longer adds a synthetic checkpoint

Previously, calling `finish()` (or any output method like `toArray()`, `toHtml()`) would automatically add an "Ended StopWatch" checkpoint. This synthetic checkpoint has been removed, only checkpoints you explicitly create are included.

The time between the last checkpoint and the end of the stopwatch is still visible in the HTML output (shown in the footer) and available via `timeSinceLastCheckpointReadable()`. If your code directly consumes `toArray()` and relies on the checkpoint count including this extra entry, you will need to adjust accordingly.

### `lastCheckpointFormatted()` now includes metadata

The output of `lastCheckpointFormatted()` now includes metadata when present. For example, a checkpoint with metadata `['queries' => 5]` will now return `[3ms / 10ms] Label (queries=5)` instead of `[3ms / 10ms] Label`. If you parse this string, you may need to update your logic.

## New features

### Configurable output per checkpoint

You can now configure where checkpoint output is emitted using `outputTo()` and the `StopwatchOutput` enum:

```php
use SanderMuller\Stopwatch\StopwatchOutput;

stopwatch()->outputTo(StopwatchOutput::Log)->start();
stopwatch()->checkpoint('First');  // Automatically logged
stopwatch()->checkpoint('Second'); // Automatically logged
```

Available modes: `Silent` (default), `Log`, `Stderr`, `Dump`.

You can also override the output for a single checkpoint:

```php
stopwatch()->checkpoint('Debug this', output: StopwatchOutput::Dump);
```

### `measure()` method

Wrap a closure to automatically create a checkpoint after execution:

```php
$result = stopwatch()->measure('Heavy computation', fn () => doExpensiveWork());
```

### `toStderr()` and `toLog()` methods

Write a full profile report to stderr or the log:

```php
stopwatch()->toStderr('Profile:');
stopwatch()->toLog('Profile:', level: 'info');
```

---

# Upgrading from Stopwatch 0.2.x to 0.3.x

## PHP version requirements

Stopwatch now uses PHP 8.3 or newer to run.

## Breaking changes

`Stopwatch::start()` is replaced by `stopwatch()->start()`, or if you prefer the non helper method, you can use `Stopwatch::new()->start()`.
