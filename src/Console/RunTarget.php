<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Console;

/**
 * Describes what a run measured, for the list view.
 *
 * @internal
 */
final class RunTarget
{
    /**
     * @param array<string, scalar|null> $frontmatter
     */
    public static function describe(array $frontmatter): string
    {
        $base = self::requestOrCommand($frontmatter);
        $exceptionClass = $frontmatter['exception_class'] ?? null;

        if (! is_string($exceptionClass) || $exceptionClass === '') {
            return $base;
        }

        return $base . ' · ' . class_basename($exceptionClass);
    }

    /**
     * @param array<string, scalar|null> $frontmatter
     */
    private static function requestOrCommand(array $frontmatter): string
    {
        foreach (['command' => 'artisan ', 'job' => 'job '] as $key => $prefix) {
            if (is_string($frontmatter[$key] ?? null) && $frontmatter[$key] !== '') {
                return $prefix . $frontmatter[$key];
            }
        }

        $method = is_string($frontmatter['method'] ?? null) ? $frontmatter['method'] . ' ' : '';
        $url = is_string($frontmatter['url'] ?? null) ? $frontmatter['url'] : '-';

        return $method . $url;
    }
}
