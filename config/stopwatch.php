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

];
