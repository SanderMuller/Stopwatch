<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

use SanderMuller\Stopwatch\Stopwatch;

/**
 * Renders a {@see RunDiff} result as markdown. Uses ASCII `->`: laravel/pao strips
 * `→` from console output when an AI agent runs the command.
 *
 * @phpstan-import-type Diff from RunDiff
 * @phpstan-import-type DiffRow from RunDiff
 *
 * @internal
 */
final class RunDiffRenderer
{
    /**
     * @param Diff $diff
     * @return list<string>
     */
    public static function markdown(array $diff): array
    {
        $lines = [
            '# Stopwatch run diff', '',
            "- **a:** {$diff['a']}",
            "- **b:** {$diff['b']}", '',
            '| Checkpoint | Δ a | Δ b | Change | Queries a -> b | HTTP a -> b |',
            '| --- | --- | --- | --- | --- | --- |',
            ...array_map(self::rowLine(...), $diff['rows']),
        ];

        $metadataLines = self::metadataLines($diff['rows']);

        if ($metadataLines !== []) {
            $lines = [...$lines, '', '## Changed metadata', '', ...$metadataLines];
        }

        return [
            ...$lines,
            ...self::section('Only in a', $diff['only_in_a']),
            ...self::section('Only in b', $diff['only_in_b']),
        ];
    }

    /**
     * @param DiffRow $row
     */
    private static function rowLine(array $row): string
    {
        $label = $row['occurrence'] > 1 ? "{$row['label']} (#{$row['occurrence']})" : $row['label'];

        return '| ' . implode(' | ', [
            Value::cell($label),
            Stopwatch::formatDuration($row['a_ms']),
            Stopwatch::formatDuration($row['b_ms']),
            self::change($row['change_ms'], $row['change_pct']),
            ($row['queries_a'] ?? '-') . ' -> ' . ($row['queries_b'] ?? '-'),
            ($row['http_a'] ?? '-') . ' -> ' . ($row['http_b'] ?? '-'),
        ]) . ' |';
    }

    private static function change(float $ms, ?float $pct): string
    {
        $text = self::signed($ms, Stopwatch::formatDuration($ms));

        return $pct === null ? $text : $text . ' (' . self::signed($pct, $pct . '%') . ')';
    }

    private static function signed(float $value, string $formatted): string
    {
        return $value >= 0 ? '+' . $formatted : $formatted;
    }

    /**
     * @param list<DiffRow> $rows
     * @return list<string>
     */
    private static function metadataLines(array $rows): array
    {
        $lines = [];

        foreach ($rows as $row) {
            foreach ($row['metadata_changes'] as $key => $change) {
                $lines[] = "- {$row['label']} · {$key}: {$change['a']} -> {$change['b']}";
            }
        }

        return $lines;
    }

    /**
     * @param list<string> $labels
     * @return list<string>
     */
    private static function section(string $title, array $labels): array
    {
        if ($labels === []) {
            return [];
        }

        return ['', "## {$title}", '', ...array_map(static fn (string $label): string => "- {$label}", $labels)];
    }
}
