<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Mcp;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Laravel\Boost\Mcp\Boost;
use Laravel\Mcp\Server\Registrar;
use Laravel\Mcp\Server\Tool;
use SanderMuller\Stopwatch\Mcp\Tools\ClearRunsTool;
use SanderMuller\Stopwatch\Mcp\Tools\DiffRunsTool;
use SanderMuller\Stopwatch\Mcp\Tools\ListRunsTool;
use SanderMuller\Stopwatch\Mcp\Tools\ShowRunTool;

/**
 * Exposes the run-log tools over MCP when the host has laravel/mcp:
 * as an own `stopwatch` server (`php artisan mcp:start stopwatch`), and inside
 * Laravel Boost's server when Boost is installed.
 *
 * Only class-name strings are referenced here, so nothing that extends a
 * laravel/mcp class is autoloaded when the package is absent.
 *
 * @internal
 */
final class McpRegistrar
{
    private const string HANDLE = 'stopwatch';

    /** @var list<class-string<Tool>> */
    public const array TOOLS = [
        ListRunsTool::class,
        ShowRunTool::class,
        DiffRunsTool::class,
        ClearRunsTool::class,
    ];

    /**
     * @param (Closure(string): bool)|null $classExists test seam
     */
    public static function register(Application $app, ?Closure $classExists = null): void
    {
        $classExists ??= class_exists(...);

        if (! $classExists(Registrar::class)) {
            return;
        }

        $app->make(Registrar::class)->local(self::HANDLE, StopwatchServer::class);

        if ($classExists(Boost::class)) {
            self::addToBoost();
        }
    }

    /**
     * Boost merges `boost.mcp.tools.include` into its tool list when its server
     * starts (undocumented key; checked against laravel/boost v2.10.2).
     */
    private static function addToBoost(): void
    {
        $included = config('boost.mcp.tools.include', []);
        $included = is_array($included) ? $included : [];

        foreach (self::TOOLS as $tool) {
            if (! in_array($tool, $included, true)) {
                $included[] = $tool;
            }
        }

        config()->set('boost.mcp.tools.include', $included);
    }
}
