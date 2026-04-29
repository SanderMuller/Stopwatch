<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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

        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (Throwable $throwable) {
            $this->captureCrash($request, $throwable);

            throw $throwable;
        }

        if ($this->stopwatch->enabled() && $this->stopwatch->started()) {
            $this->finishWithContext($request, status: $response->getStatusCode(), threw: false);
            $response->headers->set('Server-Timing', $this->stopwatch->toServerTiming());
        }

        return $response;
    }

    /**
     * Hand the Throwable to recorders via transient context — the run-log recorder reads
     * it during the {@see Stopwatch::finish()} dispatch chain to populate `exception_*`
     * frontmatter fields and the `## Exception` body section. Per-run state is cleared
     * automatically post-dispatch.
     */
    private function captureCrash(Request $request, Throwable $throwable): void
    {
        if ($this->stopwatch->enabled() && $this->stopwatch->started()) {
            $this->stopwatch->withTransientContext(Stopwatch::TRANSIENT_EXCEPTION, $throwable);
        }

        $this->finishWithContext($request, status: 500, threw: true);
    }

    private function finishWithContext(Request $request, int $status, bool $threw): void
    {
        if (! $this->stopwatch->enabled() || ! $this->stopwatch->started()) {
            return;
        }

        $context = [
            'url' => $this->stripQuery($request->fullUrl()),
            'method' => $request->method(),
            'status' => $status,
        ];

        if ($threw) {
            $context['threw'] = true;
        }

        $this->stopwatch->withRunContext($context);
        $this->stopwatch->finish();
    }

    private function stripQuery(string $url): string
    {
        $pos = strpos($url, '?');

        return $pos === false ? $url : substr($url, 0, $pos);
    }
}
