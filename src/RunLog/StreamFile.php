<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

/**
 * Reads a `<ULID>.jsonl` debug stream: all records, or a cheap summary from the
 * first and last line.
 *
 * @phpstan-import-type RunSummary from RunLogReader
 *
 * @internal
 */
final class StreamFile
{
    /** Bytes read from the end of a stream file to find its last record. */
    private const int TAIL_BYTES = 8192;

    /**
     * @return list<array<string, mixed>>
     */
    public static function records(string $path): array
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        $records = [];

        foreach ($lines as $line) {
            $record = self::decode($line);

            if ($record !== []) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * State is the end record's reason, or `unfinished` when there is no end record.
     *
     * @return RunSummary
     */
    public static function summary(string $id, string $path): array
    {
        $first = self::decode(self::firstLine($path));
        $last = self::decode(self::lastLine($path));
        $reason = Value::string($last['reason'] ?? null);

        return [
            'id' => $id,
            'frontmatter' => [
                'id' => $id,
                'recorded_at' => Value::scalarOrNull($first['at'] ?? null),
                'duration_ms' => Value::scalarOrNull($last['duration_ms'] ?? null),
                'checkpoints' => Value::scalarOrNull($last['checkpoints'] ?? null),
                'url' => Value::scalarOrNull($first['url'] ?? null),
                'method' => Value::scalarOrNull($first['method'] ?? null),
                'command' => Value::scalarOrNull($first['command'] ?? null),
                'job' => Value::scalarOrNull($first['job'] ?? null),
            ],
            'state' => ($last['type'] ?? null) === 'end' && $reason !== '' ? $reason : 'unfinished',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(?string $line): array
    {
        $decoded = $line === null ? null : json_decode($line, true);

        if (! is_array($decoded)) {
            return [];
        }

        $record = [];

        foreach ($decoded as $key => $value) {
            $record[(string) $key] = $value;
        }

        return $record;
    }

    private static function firstLine(string $path): ?string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $line = @fgets($handle);
        @fclose($handle);

        return $line === false ? null : rtrim($line, "\n");
    }

    private static function lastLine(string $path): ?string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        @fseek($handle, max(0, (int) @filesize($path) - self::TAIL_BYTES));
        $tail = (string) @fread($handle, self::TAIL_BYTES);
        @fclose($handle);

        $lines = array_values(array_filter(explode("\n", $tail), static fn (string $line): bool => $line !== ''));

        return $lines === [] ? null : $lines[count($lines) - 1];
    }
}
