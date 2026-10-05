<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Console;

/**
 * @internal The `--ctx key=value` option of {@see RunsListCommand}.
 */
final class CtxOption
{
    /**
     * Parse a repeatable `--ctx key=value` option into a `key => value` map.
     * Malformed entries (no `=`, empty key) are silently dropped.
     *
     * @param array<array-key, mixed> $raw
     * @return array<string, string>
     */
    public static function parse(array $raw): array
    {
        $parsed = [];

        foreach ($raw as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            if (! str_contains($entry, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $entry, 2);
            $key = trim($key);

            if ($key !== '') {
                $parsed[$key] = $value;
            }
        }

        return $parsed;
    }
}
