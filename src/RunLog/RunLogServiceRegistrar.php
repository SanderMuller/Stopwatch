<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

use Illuminate\Contracts\Foundation\Application;
use SanderMuller\Stopwatch\ServiceProvider;
use SanderMuller\Stopwatch\Stopwatch;

/**
 * Container wiring for the run-log feature. Extracted from {@see ServiceProvider}
 * to keep that class within its cognitive-complexity budget.
 */
final class RunLogServiceRegistrar
{
    public static function register(Application $app): void
    {
        $app->singleton(RunLogStore::class, static fn (): RunLogStore => new RunLogStore(self::resolvePath($app)));

        $app->singleton(MarkdownRunRecorder::class, static function () use ($app): MarkdownRunRecorder {
            $config = self::config();

            return new MarkdownRunRecorder(
                store: $app->make(RunLogStore::class),
                minDurationMs: self::optionalInt($config, 'min_duration_ms'),
                maxFiles: self::int($config, 'max_files', 200),
                maxAgeDays: self::int($config, 'max_age_days', 7),
                detail: self::string($config, 'detail', 'summary'),
                includeBindings: self::bool($config, 'include_bindings'),
                skipEmpty: self::bool($config, 'skip_empty', true),
            );
        });

        $app->singleton(ConsoleCommandContextProvider::class);
    }

    public static function wire(Application $app, Stopwatch $stopwatch): void
    {
        $config = self::config();

        if (($config['enabled'] ?? false) !== true) {
            return;
        }

        $stopwatch->recordRunsTo($app->make(MarkdownRunRecorder::class));
        $stopwatch->pushRunContextProvider($app->make(ConsoleCommandContextProvider::class));
    }

    private static function resolvePath(Application $app): string
    {
        $configured = self::config()['path'] ?? null;

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return $app->storagePath('stopwatch/runs');
    }

    /**
     * @return array<string, mixed>
     */
    private static function config(): array
    {
        $value = config('stopwatch.run_log');

        if (! is_array($value)) {
            return [];
        }

        $typed = [];

        /** @var mixed $entry */
        foreach ($value as $key => $entry) {
            if (is_string($key)) {
                $typed[$key] = $entry;
            }
        }

        return $typed;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function optionalInt(array $config, string $key): ?int
    {
        return is_numeric($config[$key] ?? null) ? (int) $config[$key] : null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function int(array $config, string $key, int $default): int
    {
        return is_numeric($config[$key] ?? null) ? (int) $config[$key] : $default;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function string(array $config, string $key, string $default): string
    {
        return is_string($config[$key] ?? null) ? $config[$key] : $default;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function bool(array $config, string $key, bool $default = false): bool
    {
        return ($config[$key] ?? $default) === true;
    }
}
