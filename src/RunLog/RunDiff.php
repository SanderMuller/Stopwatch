<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

use SanderMuller\Stopwatch\StopwatchCheckpoint;

/**
 * Compares the checkpoint records of two JSONL streams. Rows match by label; the
 * nth occurrence of a label in one run matches the nth occurrence in the other.
 *
 * @phpstan-type DiffRow array{label: string, occurrence: int, a_ms: float, b_ms: float, change_ms: float, change_pct: float|null, queries_a: int|null, queries_b: int|null, http_a: int|null, http_b: int|null, metadata_changes: array<string, array{a: string, b: string}>}
 * @phpstan-type Diff array{a: string, b: string, rows: list<DiffRow>, only_in_a: list<string>, only_in_b: list<string>}
 *
 * @internal
 */
final class RunDiff
{
    /**
     * @param list<array<string, mixed>> $recordsA
     * @param list<array<string, mixed>> $recordsB
     * @return Diff
     */
    public static function compare(string $a, string $b, array $recordsA, array $recordsB): array
    {
        $checkpointsA = self::keyed($recordsA);
        $checkpointsB = self::keyed($recordsB);
        $rows = [];

        foreach (array_intersect_key($checkpointsA, $checkpointsB) as $key => $left) {
            $rows[] = self::row((string) $key, $left, $checkpointsB[$key]);
        }

        return [
            'a' => $a,
            'b' => $b,
            'rows' => $rows,
            'only_in_a' => self::labels(array_diff_key($checkpointsA, $checkpointsB)),
            'only_in_b' => self::labels(array_diff_key($checkpointsB, $checkpointsA)),
        ];
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return DiffRow
     */
    private static function row(string $key, array $left, array $right): array
    {
        $aMs = Value::float($left['delta_ms'] ?? null);
        $bMs = Value::float($right['delta_ms'] ?? null);

        return [
            'label' => Value::string($left['label'] ?? ''),
            'occurrence' => (int) substr($key, (int) strrpos($key, '#') + 1),
            'a_ms' => $aMs,
            'b_ms' => $bMs,
            'change_ms' => round($bMs - $aMs, 3),
            'change_pct' => $aMs > 0 ? round((($bMs - $aMs) / $aMs) * 100, 1) : null,
            'queries_a' => Value::intOrNull($left['queries'] ?? null),
            'queries_b' => Value::intOrNull($right['queries'] ?? null),
            'http_a' => Value::intOrNull($left['http'] ?? null),
            'http_b' => Value::intOrNull($right['http'] ?? null),
            'metadata_changes' => self::metadataChanges($left['metadata'] ?? null, $right['metadata'] ?? null),
        ];
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return array<string, array<string, mixed>> keyed `label#occurrence`
     */
    private static function keyed(array $records): array
    {
        $keyed = [];
        $seen = [];

        foreach ($records as $record) {
            if (($record['type'] ?? null) !== 'checkpoint') {
                continue;
            }

            $label = Value::string($record['label'] ?? '');
            $seen[$label] = ($seen[$label] ?? 0) + 1;
            $keyed[$label . '#' . $seen[$label]] = $record;
        }

        return $keyed;
    }

    /**
     * @param array<string, array<string, mixed>> $records
     * @return list<string>
     */
    private static function labels(array $records): array
    {
        return array_values(array_map(static fn (array $record): string => Value::string($record['label'] ?? ''), $records));
    }

    /**
     * Values compare as safe JSON; a key missing on one side shows as `(absent)`.
     *
     * @return array<string, array{a: string, b: string}>
     */
    private static function metadataChanges(mixed $left, mixed $right): array
    {
        $left = is_array($left) ? $left : [];
        $right = is_array($right) ? $right : [];
        $changes = [];

        foreach (array_unique([...array_keys($left), ...array_keys($right)]) as $key) {
            $a = self::encoded($left, $key);
            $b = self::encoded($right, $key);

            if ($a !== $b) {
                $changes[(string) $key] = ['a' => $a, 'b' => $b];
            }
        }

        return $changes;
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private static function encoded(array $values, int|string $key): string
    {
        return array_key_exists($key, $values)
            ? (string) json_encode($values[$key], StopwatchCheckpoint::SAFE_JSON_FLAGS)
            : '(absent)';
    }
}
