<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * @internal Exposed as `php artisan mcp:start stopwatch`; see {@see McpRegistrar}.
 */
#[Name('Stopwatch')]
#[Version('1.0.0')]
#[Instructions('Reads runs recorded by sandermuller/stopwatch. Debug loop: set STOPWATCH_DEBUG=true (needs APP_DEBUG=true or APP_ENV local/testing), add stopwatch()->probe(\'label\', [...values]) calls, reproduce, then call stopwatch-show-run (id "latest"). Rows are in execution order; a missing probe means its code did not run. Write probes as stopwatch()->probe( so they can be found exactly, and remove every one afterwards. Use stopwatch-diff-runs to compare a run before and after a fix.')]
final class StopwatchServer extends Server
{
    protected array $tools = McpRegistrar::TOOLS;
}
