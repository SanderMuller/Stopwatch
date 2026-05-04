<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marker middleware behind the `stopwatch.inject` alias.
 *
 * Route-level usage adds this to `gatherMiddleware()`, which the global
 * StopwatchInjectMiddleware reads to decide whether to inject under
 * `mode=route` (and as a fallback under `mode=attribute` for closure routes).
 * The marker itself is a no-op so it can run inner to autostart without
 * tripping the ordering guard or duplicating injection.
 */
final readonly class StopwatchInjectAlias
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
