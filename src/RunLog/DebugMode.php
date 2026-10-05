<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

/**
 * Resolves whether debug mode (`stopwatch.debug.enabled`) is active, and why not.
 *
 * Active needs: debug requested, `stopwatch.enabled`, `app.debug` or a `local` /
 * `testing` environment, and no Octane. The environment exception exists because
 * test suites often set `APP_DEBUG=false` while the test run is where an agent
 * debugs. Octane keeps the singleton alive across requests, the same reason the
 * toolbar is disabled there.
 *
 * @internal
 */
final class DebugMode
{
    private const array PRESET = [
        'enabled' => true,
        'min_duration_ms' => 0,
        'skip_empty' => false,
        'detail' => 'full',
        'auto_lifecycle' => true,
    ];

    private const array SAFE_ENVIRONMENTS = ['local', 'testing'];

    private static bool $warned = false;

    public static function requested(): bool
    {
        return config('stopwatch.debug.enabled') === true;
    }

    public static function active(): bool
    {
        return self::requested() && self::blockedReason() === null;
    }

    /**
     * The guard that blocks a requested debug mode, or null when nothing blocks it.
     */
    public static function blockedReason(): ?string
    {
        if (config('stopwatch.enabled', true) === false) {
            return 'stopwatch.enabled is false';
        }

        if (config('app.debug') !== true && ! app()->environment(self::SAFE_ENVIRONMENTS)) {
            return 'app.debug is false and APP_ENV is ' . app()->environment();
        }

        if (app()->bound('octane')) {
            return 'the app runs under Octane';
        }

        return null;
    }

    /**
     * Force the run-log preset into config. Runs before the Stopwatch singleton and
     * the recorder read `stopwatch.run_log`, and uses no `env()` call, so it also
     * works with a cached config.
     */
    public static function applyPreset(): void
    {
        foreach (self::PRESET as $key => $value) {
            config()->set('stopwatch.run_log.' . $key, $value);
        }
    }

    /**
     * Log once per process when debug mode was requested but a guard blocks it.
     * A disabled stopwatch stays silent: that is an explicit choice, not a mistake.
     */
    public static function warnIfBlocked(): void
    {
        $reason = self::requested() ? self::blockedReason() : null;

        if (self::$warned || $reason === null || $reason === 'stopwatch.enabled is false') {
            return;
        }

        self::$warned = true;

        try {
            logger()->warning("Stopwatch debug mode is off: {$reason}.");
            app(RunLogStore::class)->writeDebugBlocked($reason);
        } catch (\Throwable) {
            // No logger bound: nothing to report to.
        }
    }

    /**
     * @internal Test seam: allow the one-time warning again.
     */
    public static function resetWarning(): void
    {
        self::$warned = false;
    }
}
