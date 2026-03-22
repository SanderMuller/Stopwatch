# Stopwatch for PHP & Laravel

Easily profile of parts of your application/code and measure the performance to expose the bottlenecks

## Installation

You can install the package via composer:

```bash
composer require sandermuller/stopwatch
```

## Usage

### Start the stopwatch

```php
stopwatch()->start();
```

### Add a lap/checkpoint

```php
stopwatch()->start();

stopwatch()->checkpoint('First checkpoint');
// Or
stopwatch()->lap('Second checkpoint');
```

### Output each checkpoint

Configure where each checkpoint is emitted using `outputTo()`:

```php
use SanderMuller\Stopwatch\StopwatchOutput;

stopwatch()->outputTo(StopwatchOutput::Log)->start();

stopwatch()->checkpoint('First checkpoint');  // Automatically logged
stopwatch()->checkpoint('Second checkpoint'); // Automatically logged
```

Available output modes:

| Mode | Description |
|------|-------------|
| `StopwatchOutput::Silent` | No output (default) |
| `StopwatchOutput::Log` | Send to Laravel log |
| `StopwatchOutput::Stderr` | Write to stderr |
| `StopwatchOutput::Dump` | Use Laravel's `dump()` |

You can override the output for a single checkpoint:

```php
stopwatch()->checkpoint('Debug this', output: StopwatchOutput::Dump);
```

Or use the `log()` shortcut to send a single checkpoint to the log:

```php
stopwatch()->log('Query executed');
stopwatch()->log('Query executed', level: 'warning');
```

### Measure a closure

Wrap a closure to automatically create a checkpoint after execution:

```php
stopwatch()->start();

$result = stopwatch()->measure('Heavy computation', function () {
    return doExpensiveWork();
});
```

### Write a full report

Write all checkpoints and the total duration to stderr or your log:

```php
stopwatch()->start();

stopwatch()->checkpoint('Validation');
stopwatch()->checkpoint('DB inserts');

// Write to stderr
stopwatch()->toStderr('Profile:');

// Or write to the log
stopwatch()->toLog('Profile:', level: 'info');
```

### Display the total run duration

```php
stopwatch()->start();

// Do something

echo stopwatch()->toString();
// Echoes something like: 116ms
```

### Render as HTML

Render a neat HTML output showing the total execution time, each checkpoint and the time between each checkpoint.

The checkpoints that took up most of the time will be highlighted.

```php
stopwatch()->start();

// Do something
stopwatch()->checkpoint('First checkpoint');

// Do something more
stopwatch()->checkpoint('Second checkpoint');

// Render the output
{{ stopwatch()->render() }}
```

![rendered-stopwatch.png](rendered-stopwatch.png)

### Manually stop the stopwatch

You can manually stop the stopwatch, but it will also stop automatically when the Stopwatch output is used (e.g. when you echo the Stopwatch object or call `->totalRunDuration()`).

```php
stopwatch()->start();

// Do something
stopwatch()->checkpoint('First checkpoint');

// Stop the stopwatch
stopwatch()->stop();

// Do something else you don't want to measure

// Finally render the output
{{ stopwatch()->render() }}
```
