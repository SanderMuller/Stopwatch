<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

use Closure;
use Illuminate\Http\Request;
use ReflectionClass;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** @phpstan-ignore complexity.classLike */
final class StopwatchInjectMiddleware
{
    public const string ALIAS = 'stopwatch.inject';

    private static bool $loggedOrderingHint = false;

    public function __construct(
        private readonly Stopwatch $stopwatch,
        private readonly StopwatchInjector $injector,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! $this->shouldInject($request, $response)) {
            return $response;
        }

        $this->injector->inject($response);

        return $response;
    }

    /** @phpstan-ignore complexity.functionLike */
    private function shouldInject(Request $request, Response $response): bool
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('stopwatch.inject', []);

        $mode = is_string($config['mode'] ?? null) ? $config['mode'] : 'off';

        if ($mode === 'off') {
            return false;
        }

        if ($this->isOctane()) {
            return false;
        }

        if (! $this->environmentAllowed($config)) {
            return false;
        }

        if (! $response->isSuccessful()) {
            return false;
        }

        if (! $this->isHtmlContentType($response)) {
            return false;
        }

        $encoding = $response->headers->get('Content-Encoding');

        if (is_string($encoding) && $encoding !== '' && strtolower($encoding) !== 'identity') {
            return false;
        }

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        if ($this->isPartialRequest($request)) {
            return false;
        }

        if (! $this->stopwatch->started() || ! $this->stopwatch->ended()) {
            $this->logOrderingHintOnce();

            return false;
        }

        return $this->modeAllows($mode, $request);
    }

    private function isOctane(): bool
    {
        return app()->bound('octane');
    }

    /** @param array<string, mixed> $config */
    private function environmentAllowed(array $config): bool
    {
        $raw = $config['allowed_environments'] ?? 'local';
        $allowed = $this->parseAllowedEnvironments($raw);

        if ($allowed === []) {
            return false;
        }

        return app()->environment(...$allowed) !== false;
    }

    /**
     * @return list<string>
     */
    private function parseAllowedEnvironments(mixed $raw): array
    {
        if (is_string($raw)) {
            return $this->normaliseNames(explode(',', $raw));
        }

        if (is_array($raw)) {
            $strings = array_filter($raw, is_string(...));

            return $this->normaliseNames($strings);
        }

        return [];
    }

    /**
     * @param array<int|string, string> $names
     * @return list<string>
     */
    private function normaliseNames(array $names): array
    {
        return array_values(array_filter(
            array_map(trim(...), $names),
            static fn (string $name): bool => $name !== '',
        ));
    }

    private function isHtmlContentType(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type');

        if (! is_string($contentType) || $contentType === '') {
            return false;
        }

        $parts = array_map(trim(...), explode(';', strtolower($contentType)));

        if (($parts[0] ?? '') !== 'text/html') {
            return false;
        }

        return $this->charsetIsUtf8OrAbsent($parts);
    }

    /** @param list<string> $parts */
    private function charsetIsUtf8OrAbsent(array $parts): bool
    {
        foreach ($parts as $part) {
            if (! str_starts_with($part, 'charset=')) {
                continue;
            }

            $charset = trim(substr($part, 8), " \"'");

            if ($charset !== 'utf-8') {
                return false;
            }
        }

        return true;
    }

    private function isPartialRequest(Request $request): bool
    {
        if ($request->ajax() || $request->wantsJson() || $request->pjax()) {
            return true;
        }

        $headers = $request->headers;
        if ($this->headerIsTrue($headers->get('HX-Request'))) {
            return true;
        }

        if ($this->headerIsTrue($headers->get('X-Livewire'))) {
            return true;
        }

        return $this->headerIsTrue($headers->get('X-Inertia'));
    }

    private function headerIsTrue(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        return strtolower(trim($value)) === 'true';
    }

    private function modeAllows(string $mode, Request $request): bool
    {
        return match ($mode) {
            'all' => true,
            'route' => $this->routeHasAlias($request),
            'attribute' => $this->routeHasAttribute($request) || $this->routeHasAlias($request),
            default => false,
        };
    }

    private function routeHasAlias(Request $request): bool
    {
        $route = $request->route();

        if (! is_object($route) || ! method_exists($route, 'gatherMiddleware')) {
            return false;
        }

        /** @var array<int, mixed> $middleware */
        $middleware = $route->gatherMiddleware();

        foreach ($middleware as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $name = explode(':', $entry, 2)[0];

            if ($name === self::ALIAS || $name === StopwatchInjectAlias::class) {
                return true;
            }
        }

        return false;
    }

    private function routeHasAttribute(Request $request): bool
    {
        $route = $request->route();

        if (! is_object($route) || ! method_exists($route, 'getControllerClass') || ! method_exists($route, 'getActionMethod')) {
            return false;
        }

        $class = $route->getControllerClass();

        if (! is_string($class) || ! class_exists($class)) {
            return false;
        }

        $reflectionClass = new ReflectionClass($class);

        if ($reflectionClass->getAttributes(ProfileViaStopwatch::class) !== []) {
            return true;
        }

        $method = $route->getActionMethod();

        if ($method === '' || ! $reflectionClass->hasMethod($method)) {
            return false;
        }

        return $reflectionClass->getMethod($method)->getAttributes(ProfileViaStopwatch::class) !== [];
    }

    private function logOrderingHintOnce(): void
    {
        if (self::$loggedOrderingHint) {
            return;
        }

        if (app()->environment('production') !== false) {
            return;
        }

        self::$loggedOrderingHint = true;

        logger()->debug(
            'StopwatchInjectMiddleware skipped because the stopwatch is not started/finished. '
            . 'Ensure StopwatchMiddleware::autoStart() is registered INNER to StopwatchInjectMiddleware '
            . "(autostart's finish() must run before inject reads aggregates)."
        );
    }

    /** @internal */
    public static function resetOrderingHintForTesting(): void
    {
        self::$loggedOrderingHint = false;
    }
}
