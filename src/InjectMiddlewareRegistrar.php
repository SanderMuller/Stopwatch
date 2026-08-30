<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;

/**
 * Puts the toolbar middleware on the global stack so `STOPWATCH_INJECT` alone
 * is enough to see a toolbar.
 *
 * Autostart finishes the stopwatch on every request, which also emits
 * `Server-Timing` and feeds the run log, so the push stays behind the same
 * default-deny environment gate as the toolbar itself.
 *
 * Prepending keeps the injector OUTER to autostart, which the injector needs:
 * it reads aggregates post-$next, so autostart's finish() must run first. A
 * prepend also survives a host that already appended autostart by hand for
 * `Server-Timing`, which the kernel de-duplicates.
 */
final readonly class InjectMiddlewareRegistrar
{
    public static function register(Application $app): void
    {
        if (! self::enabled()) {
            return;
        }

        $kernel = $app->make(HttpKernelContract::class);

        if (! $kernel instanceof HttpKernel) {
            return;
        }

        $kernel->prependMiddleware(StopwatchMiddleware::autoStart());
        $kernel->prependMiddleware(StopwatchInjectMiddleware::class);
    }

    private static function enabled(): bool
    {
        if (config('stopwatch.inject.auto_register', true) === false) {
            return false;
        }

        return InjectSettings::toolbarActive();
    }
}
