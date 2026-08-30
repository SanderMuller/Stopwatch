# Slow-run notifications

Dispatch a notification when a run crosses a duration threshold. Notifications fire when the stopwatch finishes, including implicit finishes from `render()`, `toArray()`, `toLog()` and `toStderr()`.

```php
stopwatch()->notifyIfSlowerThan(500);       // ms, or a CarbonInterval

stopwatch()->checkpoint('Fetch order');
stopwatch()->checkpoint('Generate PDF');

stopwatch()->finish();
```

Set it once in a service provider, or from the environment — paired with `StopwatchMiddleware`, every slow request then notifies on its own:

```dotenv
STOPWATCH_NOTIFY_THRESHOLD=500
```

## Channels

```php
// config/stopwatch.php
'notification_channels' => [
    \SanderMuller\Stopwatch\Notifications\LogChannel::class,
    \SanderMuller\Stopwatch\Notifications\MailChannel::class,
],
```

`MailChannel` emails the HTML report:

```dotenv
STOPWATCH_MAIL_TO=dev-team@example.com
STOPWATCH_MAIL_SUBJECT="Slow request detected"
```

<details>
<summary>Constructor binding and runtime channels</summary>

```php
$this->app->bind(MailChannel::class, fn () => new MailChannel(
    to: 'dev-team@example.com',
    subject: 'Slow request',
));

stopwatch()->notifyUsing([new SlackChannel()]);
```

</details>

## Your own channel

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

Register it in `notification_channels` alongside the shipped ones.
