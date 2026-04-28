<?php declare(strict_types=1);
use SanderMuller\Stopwatch\StopwatchOutput;

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | When disabled, all stopwatch calls become no-ops with near-zero
    | overhead. This lets you leave stopwatch() calls in your code and
    | simply disable it in production via your .env file.
    |
    */

    'enabled' => (bool) env('STOPWATCH_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Default Output
    |--------------------------------------------------------------------------
    |
    | Where checkpoints are emitted by default. Can be overridden per
    | checkpoint or by calling outputTo() on the stopwatch instance.
    |
    | Supported: "silent", "log", "stderr", "dump"
    |
    */

    'output' => env('STOPWATCH_OUTPUT', StopwatchOutput::Silent->value),

    /*
    |--------------------------------------------------------------------------
    | Log Level
    |--------------------------------------------------------------------------
    |
    | The default log level used when output is set to "log".
    |
    */

    'log_level' => env('STOPWATCH_LOG_LEVEL', 'debug'),

    /*
    |--------------------------------------------------------------------------
    | Slow Checkpoint Threshold
    |--------------------------------------------------------------------------
    |
    | Checkpoints that take longer than this (in milliseconds) will be
    | highlighted in the HTML report.
    |
    */

    'slow_threshold' => (int) env('STOPWATCH_SLOW_THRESHOLD', 50),

    /*
    |--------------------------------------------------------------------------
    | Track Queries
    |--------------------------------------------------------------------------
    |
    | When enabled, query count and total query time will automatically
    | be added as metadata to each checkpoint.
    |
    */

    'track_queries' => (bool) env('STOPWATCH_TRACK_QUERIES', false),

    /*
    |--------------------------------------------------------------------------
    | Track Memory
    |--------------------------------------------------------------------------
    |
    | When enabled, memory usage will automatically be added as metadata
    | to each checkpoint.
    |
    */

    'track_memory' => (bool) env('STOPWATCH_TRACK_MEMORY', false),

    /*
    |--------------------------------------------------------------------------
    | Track HTTP Calls
    |--------------------------------------------------------------------------
    |
    | When enabled, outbound HTTP requests sent through Laravel's `Http::`
    | facade will be captured per checkpoint (count + total time + per-call
    | summaries). Direct Guzzle clients bypass this (same limitation as
    | Telescope) — use the facade if you want them tracked.
    |
    */

    'track_http' => (bool) env('STOPWATCH_TRACK_HTTP', false),

    /*
    |--------------------------------------------------------------------------
    | Notification Channels
    |--------------------------------------------------------------------------
    |
    | Channels to use when notifyIfSlowerThan() triggers. Each entry
    | should be a class name implementing StopwatchNotificationChannel.
    | The class will be resolved from the container, so you can
    | bind custom constructor arguments if needed.
    |
    */

    'notification_channels' => [],

    /*
    |--------------------------------------------------------------------------
    | Notification Threshold
    |--------------------------------------------------------------------------
    |
    | When set, notifications will be dispatched via the configured
    | notification channels if the total stopwatch duration exceeds
    | this value (in milliseconds). Set to null to disable.
    |
    */

    'notify_threshold' => env('STOPWATCH_NOTIFY_THRESHOLD'),

    /*
    |--------------------------------------------------------------------------
    | Mail Notification Settings
    |--------------------------------------------------------------------------
    |
    | Settings for the MailChannel notification channel. The recipient
    | address is required when using MailChannel. The subject line
    | defaults to including the total duration.
    |
    */

    'mail' => [
        'to' => env('STOPWATCH_MAIL_TO'),
        'subject' => env('STOPWATCH_MAIL_SUBJECT'),
    ],

];
