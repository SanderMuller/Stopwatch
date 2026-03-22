<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class StopwatchMiddleware
{
    public function __construct(
        private Stopwatch $stopwatch,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->stopwatch->enabled()) {
            $this->stopwatch->start();
        }

        /** @var Response $response */
        $response = $next($request);

        if ($this->stopwatch->enabled()) {
            $this->stopwatch->finish();
            $response->headers->set('Server-Timing', $this->stopwatch->toServerTiming());
        }

        return $response;
    }
}
