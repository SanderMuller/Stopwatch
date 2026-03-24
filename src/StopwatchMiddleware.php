<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class StopwatchMiddleware
{
    private const string AUTOSTART = 'autostart';

    public function __construct(
        private Stopwatch $stopwatch,
    ) {}

    public static function autoStart(): string
    {
        return self::class . ':' . self::AUTOSTART;
    }

    public function handle(Request $request, Closure $next, string ...$options): Response
    {
        $autoStart = in_array(self::AUTOSTART, $options, true);

        if ($autoStart && $this->stopwatch->enabled() && ! $this->stopwatch->started()) {
            $this->stopwatch->start();
        }

        /** @var Response $response */
        $response = $next($request);

        if ($this->stopwatch->enabled() && $this->stopwatch->started()) {
            $this->stopwatch->finish();
            $response->headers->set('Server-Timing', $this->stopwatch->toServerTiming());
        }

        return $response;
    }
}
