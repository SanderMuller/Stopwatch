<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

/**
 * Reads the `stopwatch.inject.allowed_environments` allow-list.
 *
 * Shared by {@see StopwatchInjectMiddleware} (per request) and
 * {@see InjectMiddlewareRegistrar} (once at boot), so both answer the
 * question the same way. Default-deny: an empty or unreadable list allows
 * nothing.
 */
final readonly class InjectEnvironmentGate
{
    public static function allows(mixed $allowedEnvironments): bool
    {
        $allowed = self::parse($allowedEnvironments);

        if ($allowed === []) {
            return false;
        }

        return app()->environment(...$allowed) !== false;
    }

    /** @return list<string> */
    private static function parse(mixed $raw): array
    {
        if (is_string($raw)) {
            return self::normaliseNames(explode(',', $raw));
        }

        if (is_array($raw)) {
            return self::normaliseNames(array_filter($raw, is_string(...)));
        }

        return [];
    }

    /**
     * @param array<int|string, string> $names
     * @return list<string>
     */
    private static function normaliseNames(array $names): array
    {
        return array_values(array_filter(
            array_map(trim(...), $names),
            static fn (string $name): bool => $name !== '',
        ));
    }
}
