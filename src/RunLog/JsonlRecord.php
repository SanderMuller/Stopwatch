<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

use SanderMuller\Stopwatch\StopwatchCheckpoint;

/**
 * Builds the `checkpoint` record of the debug stream.
 *
 * @internal
 */
final class JsonlRecord
{
    /**
     * @return array<string, mixed>
     */
    public static function checkpoint(StopwatchCheckpoint $checkpoint, int $index, int $valueMaxBytes): array
    {
        return [
            'type' => 'checkpoint',
            'i' => $index,
            'label' => $checkpoint->label,
            'probe' => $checkpoint->probe,
            'location' => $checkpoint->location,
            'delta_ms' => round($checkpoint->timeSinceLastCheckpoint->totalMilliseconds, 3),
            'total_ms' => round($checkpoint->timeSinceStopwatchStart->totalMilliseconds, 3),
            'metadata' => $checkpoint->metadata === null ? null : self::capValues($checkpoint->metadata, $valueMaxBytes),
            'queries' => $checkpoint->queryCount,
            'query_ms' => $checkpoint->queryTimeMs,
            'http' => $checkpoint->httpCount,
            'http_ms' => $checkpoint->httpTimeMs,
            'memory_delta' => $checkpoint->memoryDelta,
        ];
    }

    /**
     * A value whose JSON is over the cap becomes `…(truncated N bytes) <first bytes of the JSON>`.
     *
     * @param array<array-key, mixed> $metadata
     * @return array<array-key, mixed>
     */
    private static function capValues(array $metadata, int $valueMaxBytes): array
    {
        foreach ($metadata as $key => $value) {
            $json = (string) json_encode($value, StopwatchCheckpoint::SAFE_JSON_FLAGS);

            if (strlen($json) > $valueMaxBytes) {
                $metadata[$key] = '…(truncated ' . (strlen($json) - $valueMaxBytes) . ' bytes) ' . mb_strcut($json, 0, $valueMaxBytes);
            }
        }

        return $metadata;
    }
}
