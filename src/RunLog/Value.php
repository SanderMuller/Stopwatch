<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

/**
 * Narrowing helpers for values decoded from JSONL records, which are `mixed`.
 *
 * @internal
 */
final class Value
{
    public static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    public static function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    public static function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    public static function scalarOrNull(mixed $value): string|int|float|bool|null
    {
        return is_scalar($value) ? $value : null;
    }

    /**
     * Escape a value for a markdown table cell.
     */
    public static function cell(string $value): string
    {
        return str_replace(['|', "\n", "\r"], ['\\|', ' ', ''], $value);
    }
}
