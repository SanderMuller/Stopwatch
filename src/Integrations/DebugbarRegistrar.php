<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Integrations;

use DebugBar\DebugBar;
use Illuminate\Contracts\Foundation\Application;
use SanderMuller\Stopwatch\Stopwatch;

/**
 * Adds the stopwatch timeline tab to Laravel Debugbar when it is installed and enabled.
 */
final readonly class DebugbarRegistrar
{
    /**
     * Debugbar's main class, newest namespace first. v4 ships as
     * `fruitcake/laravel-debugbar` under `Fruitcake\LaravelDebugbar` and
     * registers no back-compat alias for the v3 `Barryvdh\Debugbar` name.
     *
     * @var list<string>
     */
    private const array DEBUGBAR_CLASSES = [
        'Fruitcake\\LaravelDebugbar\\LaravelDebugbar',
        'Barryvdh\\Debugbar\\LaravelDebugbar',
    ];

    public static function register(Application $app): void
    {
        $debugBar = self::resolve($app);

        if (! $debugBar instanceof DebugBar || $debugBar->hasCollector(DebugbarCollector::NAME)) {
            return;
        }

        if (method_exists($debugBar, 'isEnabled') && $debugBar->isEnabled() !== true) {
            return;
        }

        $debugBar->addCollector(new DebugbarCollector($app->make(Stopwatch::class)));
    }

    private static function resolve(Application $app): ?DebugBar
    {
        foreach (self::DEBUGBAR_CLASSES as $class) {
            if (! class_exists($class) || ! $app->bound($class)) {
                continue;
            }

            $instance = $app->make($class);

            if ($instance instanceof DebugBar) {
                return $instance;
            }
        }

        return null;
    }
}
