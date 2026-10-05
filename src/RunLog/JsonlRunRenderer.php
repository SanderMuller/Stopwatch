<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\StopwatchCheckpoint;

/**
 * Renders a JSONL debug stream as a markdown table with its own fixed columns.
 * Used for runs that have no markdown file (still active, crashed, or restarted).
 *
 * @internal
 */
final class JsonlRunRenderer
{
    /**
     * @param list<array<string, mixed>> $records
     */
    public static function render(array $records, string $state): string
    {
        $checkpoints = array_values(array_filter($records, static fn (array $record): bool => ($record['type'] ?? null) === 'checkpoint'));

        return implode("\n", [
            '# Stopwatch run (' . $state . ')', '',
            ...self::startLines($records[0] ?? []),
            ...self::endLines(self::lastOfType($records, 'end'), self::lastOfType($records, 'truncated'), count($checkpoints)),
            '',
            '| # | Checkpoint | Probe | Δ | Cumulative | Queries | HTTP | Location | Metadata |',
            '| --- | --- | --- | --- | --- | --- | --- | --- | --- |',
            ...array_map(self::rowLine(...), $checkpoints),
        ]);
    }

    /**
     * @param array<string, mixed> $start
     * @return list<string>
     */
    private static function startLines(array $start): array
    {
        $lines = ['- **Run:** ' . Value::string($start['run'] ?? '-'), '- **Started:** ' . Value::string($start['at'] ?? '-')];

        foreach (['url' => 'URL', 'method' => 'Method', 'command' => 'Command', 'job' => 'Job'] as $key => $title) {
            $value = Value::string($start[$key] ?? null);

            if ($value !== '') {
                $lines[] = "- **{$title}:** {$value}";
            }
        }

        return $lines;
    }

    /**
     * @param array<string, mixed>|null $end
     * @param array<string, mixed>|null $truncated
     * @return list<string>
     */
    private static function endLines(?array $end, ?array $truncated, int $written): array
    {
        if ($end === null) {
            $lines = ['- **Checkpoints:** ' . $written, '- **End:** none recorded (memory or time limit, killed process, or still running)'];
        } else {
            $lines = [
                '- **Checkpoints:** ' . $written . ' of ' . Value::string($end['checkpoints'] ?? $written),
                '- **End:** ' . Value::string($end['reason'] ?? '-') . ' after ' . Stopwatch::formatDuration(Value::float($end['duration_ms'] ?? 0)),
            ];
        }

        if ($truncated !== null) {
            $lines[] = '- **Truncated:** records after #' . Value::string($truncated['after'] ?? '?') . ' were counted, not written';
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $checkpoint
     */
    private static function rowLine(array $checkpoint): string
    {
        $queries = Value::intOrNull($checkpoint['queries'] ?? null);
        $http = Value::intOrNull($checkpoint['http'] ?? null);
        $metadata = $checkpoint['metadata'] ?? null;

        return '| ' . implode(' | ', [
            Value::string($checkpoint['i'] ?? ''),
            Value::cell(Value::string($checkpoint['label'] ?? '')),
            ($checkpoint['probe'] ?? false) === true ? 'yes' : '',
            Stopwatch::formatDuration(Value::float($checkpoint['delta_ms'] ?? 0)),
            Stopwatch::formatDuration(Value::float($checkpoint['total_ms'] ?? 0)),
            $queries === null ? '' : $queries . 'q',
            $http === null ? '' : $http . 'h',
            Value::cell(Value::string($checkpoint['location'] ?? '')),
            $metadata === null ? '' : Value::cell((string) json_encode($metadata, StopwatchCheckpoint::SAFE_JSON_FLAGS)),
        ]) . ' |';
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return array<string, mixed>|null
     */
    private static function lastOfType(array $records, string $type): ?array
    {
        $matches = array_filter($records, static fn (array $record): bool => ($record['type'] ?? null) === $type);

        return $matches === [] ? null : end($matches);
    }
}
