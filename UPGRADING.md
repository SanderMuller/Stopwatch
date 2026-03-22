# Upgrading from Stopwatch 0.3.x to 0.4.x

## Breaking changes

### `finish()` no longer adds a synthetic checkpoint

Previously, calling `finish()` (or any output method like `toArray()`, `toHtml()`) would automatically add an "Ended StopWatch" checkpoint. This synthetic checkpoint has been removed. Only checkpoints you explicitly create are included.

The time between the last checkpoint and the end of the stopwatch is still visible in the HTML output (shown in the footer) and available via the new `timeSinceLastCheckpointReadable()` method.

### `lastCheckpointFormatted()` now includes metadata

The output of `lastCheckpointFormatted()` now includes metadata when present. For example, a checkpoint with metadata `['queries' => 5]` will now return `[3ms / 10ms] Label (queries=5)` instead of `[3ms / 10ms] Label`. If you parse this string, you may need to update your logic.

### Constructor is now private

Use `Stopwatch::new()` or the `stopwatch()` helper to create instances. Direct `new Stopwatch()` calls will need to be updated.

---

# Upgrading from Stopwatch 0.2.x to 0.3.x

## PHP version requirements

Stopwatch now uses PHP 8.3 or newer to run.

## Breaking changes

`Stopwatch::start()` is replaced by `stopwatch()->start()`, or if you prefer the non helper method, you can use `Stopwatch::new()->start()`.
