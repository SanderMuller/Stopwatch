# Stopwatch for PHP & Laravel

Easily profile parts of your application/code and measure the performance to expose the bottlenecks.

**Requires PHP 8.3+**

## Installation

You can install the package via composer:

```bash
composer require sandermuller/stopwatch
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag=stopwatch-config
```

## Configuration

All settings can be configured via environment variables or the `config/stopwatch.php` file:

| Setting | Env Variable | Default | Description |
|---|---|---|---|
| `enabled` | `STOPWATCH_ENABLED` | `true` | Disable to make all calls no-ops with near-zero overhead |
| `output` | `STOPWATCH_OUTPUT` | `silent` | Default output mode (`silent`, `log`, `stderr`, `dump`) |
| `log_level` | `STOPWATCH_LOG_LEVEL` | `debug` | Log level when output is `log` |
| `slow_threshold` | `STOPWATCH_SLOW_THRESHOLD` | `50` | Highlight checkpoints slower than this (ms) |
| `track_queries` | `STOPWATCH_TRACK_QUERIES` | `false` | Auto-track query count and duration per checkpoint |
| `track_memory` | `STOPWATCH_TRACK_MEMORY` | `false` | Auto-track memory usage per checkpoint |

## Usage

### Checkpoints

```php
stopwatch()->checkpoint('First checkpoint');
stopwatch()->checkpoint('Second checkpoint');
stopwatch()->lap('Third checkpoint'); // alias for checkpoint()
```

Calling `checkpoint()` auto-starts the stopwatch if it hasn't been started yet. You can also start it explicitly with `stopwatch()->start()`.

You can attach metadata to any checkpoint:

```php
stopwatch()->checkpoint('Query executed', ['table' => 'users', 'rows' => 42]);
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

Wrap a closure to automatically create a checkpoint after execution. Auto-starts the stopwatch if needed.

```php
$result = stopwatch()->measure('Heavy computation', function () {
    return doExpensiveWork();
});
```

### Query tracking

Automatically track the number of database queries and their total duration between each checkpoint. Requires `illuminate/database`.

```php
stopwatch()->withQueryTracking()->start();

User::all();
stopwatch()->checkpoint('Load users');
// Checkpoint includes: 1q / 2.3ms

Order::where('status', 'pending')->get();
stopwatch()->checkpoint('Load orders');
// Checkpoint includes: 1q / 1.5ms
```

Can also be enabled via config (`STOPWATCH_TRACK_QUERIES=true`).

### Memory tracking

Track memory usage changes between each checkpoint:

```php
stopwatch()->withMemoryTracking()->start();

$data = loadLargeDataset();
stopwatch()->checkpoint('Load data');
// Checkpoint includes: +2.4MB
```

In the HTML output, memory is shown as a compact delta badge with full details on hover (current usage, delta, peak). In plain-text output (`toStderr`, `toLog`), the delta is included inline. Can also be enabled via config (`STOPWATCH_TRACK_MEMORY=true`).

Both tracking methods can be combined:

```php
stopwatch()->withQueryTracking()->withMemoryTracking()->start();
```

### Write a full report

Write all checkpoints and the total duration to stderr or your log:

```php
stopwatch()->checkpoint('Validation');
stopwatch()->checkpoint('DB inserts');

// Write to stderr
stopwatch()->toStderr('Profile:');

// Or write to the log
stopwatch()->toLog('Profile:', level: 'info');
```

### Conditional notifications

Get notified when a request or operation exceeds a time threshold. Set up the threshold as a hook — notifications are dispatched automatically when the stopwatch finishes:

```php
stopwatch()->notifyIfSlowerThan(500);

stopwatch()->checkpoint('Fetch order');
stopwatch()->checkpoint('Generate PDF');
stopwatch()->checkpoint('Upload to S3');

stopwatch()->finish(); // notifications dispatch here if total >= 500ms
```

The threshold is also checked on implicit finishes (`render()`, `toArray()`, `toLog()`, etc.), and also accepts `CarbonInterval`:

```php
stopwatch()->notifyIfSlowerThan(CarbonInterval::seconds(2));
```

This pairs well with the middleware — set the threshold in a service provider, and every request that exceeds it will trigger a notification:

```php
// AppServiceProvider::boot()
stopwatch()->notifyIfSlowerThan(500);
```

By default, notifications are sent to the log using the `LogChannel`. You can configure which channels are used in `config/stopwatch.php`:

```php
'notification_channels' => [
    \SanderMuller\Stopwatch\Notifications\LogChannel::class,
],
```

#### Email notifications

Add `MailChannel` to receive an email with the stopwatch's HTML report when a threshold is exceeded:

```php
'notification_channels' => [
    \SanderMuller\Stopwatch\Notifications\LogChannel::class,
    \SanderMuller\Stopwatch\Notifications\MailChannel::class,
],
```

Configure the recipient in your `.env`:

```env
STOPWATCH_MAIL_TO=dev-team@example.com
STOPWATCH_MAIL_SUBJECT="Slow request detected"  # optional
```

Or bind the channel with constructor arguments:

```php
$this->app->bind(MailChannel::class, fn () => new MailChannel(
    to: 'dev-team@example.com',
    subject: 'Slow request',
));
```

#### Custom notification channels

Create your own channel by implementing `StopwatchNotificationChannel`:

```php
use SanderMuller\Stopwatch\Notifications\StopwatchNotificationChannel;
use SanderMuller\Stopwatch\Stopwatch;

class SlackChannel implements StopwatchNotificationChannel
{
    public function notify(Stopwatch $stopwatch): void
    {
        Slack::message("Slow request: {$stopwatch->totalRunDurationReadable()}");
    }
}
```

Register it in your config:

```php
'notification_channels' => [
    \SanderMuller\Stopwatch\Notifications\LogChannel::class,
    \App\Stopwatch\SlackChannel::class,
],
```

Or set channels at runtime:

```php
stopwatch()->notifyUsing([new SlackChannel()]);
```

### Render as HTML

Render a neat HTML output showing the total execution time, each checkpoint and the time between each checkpoint.

The checkpoints that took up most of the time will be highlighted.

```php
stopwatch()->checkpoint('First checkpoint');
stopwatch()->checkpoint('Second checkpoint');

// Render the output
{{ stopwatch()->render() }}
```

Or use the Blade directive:

```blade
@stopwatch
```

![rendered-stopwatch.png](rendered-stopwatch.png)

### Server-Timing header

Add a `Server-Timing` HTTP header to your responses so you can inspect checkpoint timings in the browser's DevTools Network tab.

Use the middleware to automatically add it to all responses. The middleware starts and finishes the stopwatch for you:

```php
// bootstrap/app.php
use SanderMuller\Stopwatch\StopwatchMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(StopwatchMiddleware::class);
    })
    // ...
```

Or add the header manually:

```php
return response('OK')
    ->header('Server-Timing', stopwatch()->toServerTiming());
```

### Manually stop the stopwatch

You can manually stop the stopwatch to freeze the timing. It will also stop automatically when output is rendered (e.g. `render()`, `toArray()`, `toStderr()`).

```php
stopwatch()->checkpoint('First checkpoint');

// Stop the stopwatch
stopwatch()->stop();

// Do something else you don't want to measure

// Finally render the output
{{ stopwatch()->render() }}
```

You can get the total duration as a string with `stopwatch()->toString()` (e.g. `"116ms"`).

### Without Laravel

You can use the stopwatch without the Laravel helper by creating instances directly:

```php
$stopwatch = \SanderMuller\Stopwatch\Stopwatch::new();
$stopwatch->start();
$stopwatch->checkpoint('Done');
echo $stopwatch->toString();
```
