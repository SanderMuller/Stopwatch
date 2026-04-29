<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

use Throwable;

/**
 * Defensive debug-log helper shared by {@see ContextCapture} and
 * {@see ContextPromoter} for emitting "key was skipped — here's why" breadcrumbs.
 *
 * Wraps {@see logger()} in `try/catch` so a missing or broken logger never
 * propagates out of the recorder dispatch path.
 *
 * @internal
 */
final class ContextCaptureLogger
{
    public static function skip(string $component, string $key, string $reason): void
    {
        try {
            if (function_exists('logger')) {
                logger()->debug('Stopwatch ' . $component . ': ' . $reason, ['key' => $key]);
            }
        } catch (Throwable) {
            // logger unavailable — swallow.
        }
    }
}
