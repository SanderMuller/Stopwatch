<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

use Illuminate\Contracts\Foundation\Application;
use SanderMuller\Stopwatch\ServiceProvider;
use SanderMuller\Stopwatch\Stopwatch;

/**
 * Container wiring for the run-log feature. Extracted from {@see ServiceProvider}
 * to keep that class within its cognitive-complexity budget.
 *
 * Helpers for config decoding live in {@see ConfigReader}; collector construction
 * lives in dedicated {@see CollectorBuilder} static helpers.
 */
final class RunLogServiceRegistrar
{
    public static function register(Application $app): void
    {
        $app->singleton(RunLogStore::class, static fn (): RunLogStore => new RunLogStore(self::resolvePath($app)));
        $app->singleton(MarkdownRunRecorder::class, static fn (): MarkdownRunRecorder => self::buildRecorder($app));
        $app->singleton(ConsoleCommandContextProvider::class);
    }

    public static function wire(Application $app, Stopwatch $stopwatch): void
    {
        $configReader = self::reader();

        if (! $configReader->bool('enabled')) {
            return;
        }

        $stopwatch->recordRunsTo($app->make(MarkdownRunRecorder::class));
        $stopwatch->pushRunContextProvider($app->make(ConsoleCommandContextProvider::class));
    }

    private static function buildRecorder(Application $app): MarkdownRunRecorder
    {
        $configReader = self::reader();
        $optionsExceptions = $configReader->nested('options')->nested('exceptions');
        $optionsContext = $configReader->nested('options')->nested('context');

        return new MarkdownRunRecorder(
            store: $app->make(RunLogStore::class),
            minDurationMs: $configReader->optionalInt('min_duration_ms'),
            maxFiles: $configReader->int('max_files', 200),
            maxAgeDays: $configReader->int('max_age_days', 7),
            detail: $configReader->string('detail', 'summary'),
            includeBindings: $configReader->bool('include_bindings'),
            skipEmpty: $configReader->bool('skip_empty', true),
            collectExceptions: $configReader->bool('collect_exceptions', true),
            exceptionRenderer: CollectorBuilder::exceptionRenderer($optionsExceptions),
            collectContext: $configReader->bool('collect_context'),
            contextCapture: CollectorBuilder::contextCapture($optionsContext),
            contextRenderer: new ContextCaptureRenderer(),
        );
    }

    private static function resolvePath(Application $app): string
    {
        $configured = self::reader()->string('path', '');

        return $configured !== '' ? $configured : $app->storagePath('stopwatch/runs');
    }

    private static function reader(): ConfigReader
    {
        return ConfigReader::fromMaybeArray(config('stopwatch.run_log'));
    }
}
