<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

/**
 * Tiny scalar encoder/decoder for the run-log frontmatter format. Only handles
 * the small set of types the writer ever emits — ints, floats, bools, null,
 * single-line strings — and refuses to widen.
 *
 * Two encoders for two needs:
 *  - {@see encode()}: built-in typed fields (`duration_ms: 487`). Reader knows the
 *    expected type so the literal coercion in {@see decode()} round-trips correctly.
 *  - {@see encodeStringSafe()}: user-supplied string values that may collide with
 *    the literal-coercion rules (e.g. a `Context::add('trace_id', 'true')` that
 *    must round-trip as the string `'true'`, not the bool `true`). Wraps ambiguous
 *    strings in single quotes; the matching read path in {@see decode()} strips them.
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

    /**
     * Round-trip-safe encoder for arbitrary user-supplied scalar values.
     *
     * Strings that would otherwise be auto-decoded as `null`/`true`/`false`/numeric,
     * or that have leading/trailing whitespace, or that are empty, or that already
     * start AND end with `'` (so a naive decode would strip them), are wrapped in
     * single quotes. Inner single quotes are escaped as `''` (YAML-1.1 compatible).
     *
     * Non-string scalars defer to {@see encode()} — they round-trip unambiguously.
     */
    public static function encodeStringSafe(string|int|float|bool|null $value): string
    {
        if (! is_string($value)) {
            return self::encode($value);
        }

        $stripped = str_replace(["\r", "\n"], [' ', ' '], $value);

        if (! self::needsQuoting($stripped)) {
            return $stripped;
        }

        return "'" . str_replace("'", "''", $stripped) . "'";
    }

    public static function decode(string $value): string|int|float|bool|null
    {
        // Quoted-string path — written by encodeStringSafe(). Outer single quotes are
        // the marker that this is a literal string, not a value subject to the
        // literal-coercion rules below.
        if (strlen($value) >= 2 && str_starts_with($value, "'") && str_ends_with($value, "'")) {
            return str_replace("''", "'", substr($value, 1, -1));
        }

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

    private static function needsQuoting(string $value): bool
    {
        if ($value === '') {
            return true;
        }

        if ($value !== trim($value)) {
            return true;
        }

        if (preg_match('/^(null|true|false|-?\d+(\.\d+)?)$/i', $value) === 1) {
            return true;
        }

        return strlen($value) >= 2 && str_starts_with($value, "'") && str_ends_with($value, "'");
    }
}
