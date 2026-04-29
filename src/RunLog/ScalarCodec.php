<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

/**
 * Tiny scalar encoder/decoder for the run-log frontmatter format. Only handles
 * the small set of types the writer ever emits — ints, floats, bools, null,
 * single-line strings — and refuses to widen.
 */
final class ScalarCodec
{
    public static function encode(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            // Strip newlines defensively — frontmatter must remain single-line per key.
            default => str_replace(["\r", "\n"], [' ', ' '], $value),
        };
    }

    public static function decode(string $value): string|int|float|bool|null
    {
        $lower = strtolower($value);

        return match (true) {
            $value === '', $lower === 'null' => null,
            $lower === 'true' => true,
            $lower === 'false' => false,
            preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
            preg_match('/^-?\d+\.\d+$/', $value) === 1 => (float) $value,
            default => $value,
        };
    }
}
