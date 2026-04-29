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

    /*
    |--------------------------------------------------------------------------
    | Run Log
    |--------------------------------------------------------------------------
    |
    | When enabled, every finished stopwatch run is persisted as a markdown
    | file under `storage/stopwatch/runs/<ULID>.md` so an AI skill — or a
    | human — can later inspect slow requests via the artisan commands
    | (`stopwatch:runs:list`, `stopwatch:runs:show`, `stopwatch:runs:clear`).
    |
    | `min_duration_ms` — only log runs at or above this duration (default 50ms,
    | matching `slow_threshold`). Set to 0 to log everything.
    |
    | `detail` — `summary` (per-checkpoint table only) or `full` (also per-call
    | SQL and HTTP detail tables). SQL bindings are NEVER persisted unless
    | `include_bindings=true` (PII opt-in).
    |
    | `skip_empty` — skip runs that finished with zero checkpoints (typical for
    | autoStart middleware on routes with no `stopwatch()->checkpoint()` calls).
    |
    | Note: this feature is Laravel-only and not supported under Octane/Swoole
    | until the stopwatch lifecycle becomes per-request.
    |
    */

    'run_log' => [
        'enabled' => (bool) env('STOPWATCH_LOG_RUNS', false),
        'path' => env('STOPWATCH_LOG_DIR'),
        'min_duration_ms' => (int) env('STOPWATCH_LOG_MIN_DURATION_MS', 50),
        'max_files' => (int) env('STOPWATCH_LOG_MAX_FILES', 200),
        'max_age_days' => (int) env('STOPWATCH_LOG_MAX_AGE_DAYS', 7),
        'detail' => env('STOPWATCH_LOG_DETAIL', 'summary'),
        'include_bindings' => (bool) env('STOPWATCH_LOG_INCLUDE_BINDINGS', false),
        'skip_empty' => (bool) env('STOPWATCH_LOG_SKIP_EMPTY', true),
    ],

];
