<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

/**
 * Answers whether the toolbar is live for this app, so the middleware
 * registration and the tracking defaults agree on one condition.
 */
final readonly class InjectSettings
{
    public static function toolbarActive(): bool
    {
        if (config('stopwatch.enabled', true) === false) {
            return false;
        }

        // Octane keeps the Stopwatch singleton alive across requests, so a
        // stale run would be reported. The injector refuses there as well.
        if (app()->bound('octane')) {
            return false;
        }

        if (config('stopwatch.inject.mode', 'off') === 'off') {
            return false;
        }

        return InjectEnvironmentGate::allows(config('stopwatch.inject.allowed_environments', 'local'));
    }

    /**
     * The toolbar prints a query count, an HTTP count and a memory delta per
     * checkpoint. Without tracking those columns are dashes, so an active
     * toolbar turns the three trackers on unless the host opts out.
     */
    public static function tracksForToolbar(): bool
    {
        return config('stopwatch.inject.track', true) !== false && self::toolbarActive();
    }
}
