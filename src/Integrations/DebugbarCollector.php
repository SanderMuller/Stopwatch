<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Integrations;

use Carbon\CarbonImmutable;
use DebugBar\DataCollector\DataCollector;
use DebugBar\DataCollector\Renderable;
use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\StopwatchCheckpoint;

final class DebugbarCollector extends DataCollector implements Renderable
{
    public function __construct(
        private readonly Stopwatch $stopwatch,
    ) {}

    public function getName(): string
    {
        return 'stopwatch';
    }

    /**
     * @return array{
     *     start: float,
     *     end: float,
     *     duration: float,
     *     duration_str: string,
     *     measures: list<array{
     *         label: string,
     *         start: float,
     *         relative_start: float,
     *         end: float,
     *         relative_end: float,
     *         duration: float,
     *         duration_str: string,
     *         params: array<string, mixed>,
     *         collector: null,
     *     }>,
     * }
     */
    public function collect(): array
    {
        $data = $this->stopwatch->toArray();

        $requestStart = $this->resolveRequestStart();
        $totalSeconds = $data['totalRunDurationMs'] / 1000;

        $measures = [];

        /** @var array{label: string, timeSinceLastCheckpointMs: int, timeSinceLastCheckpointFormatted: string, totalTimeElapsedMs: int, metadata: array<array-key, mixed>|null, queryCount: int|null, queryTimeMs: float|null, memoryDelta: int|null} $checkpoint */
        foreach ($data['checkpoints'] as $checkpoint) {
            $measures[] = $this->buildMeasure($checkpoint, $requestStart);
        }

        return [
            'start' => $requestStart,
            'end' => $requestStart + $totalSeconds,
            'duration' => $totalSeconds,
            'duration_str' => $data['totalRunDuration'],
            'measures' => $measures,
        ];
    }

    private function resolveRequestStart(): float
    {
        $startTime = $this->stopwatch->startTime();

        if ($startTime instanceof CarbonImmutable) {
            return (float) $startTime->format('U.u');
        }

        if (defined('LARAVEL_START') && is_numeric(LARAVEL_START)) {
            return (float) LARAVEL_START;
        }

        return microtime(true);
    }

    /**
     * @param array{label: string, timeSinceLastCheckpointMs: int, timeSinceLastCheckpointFormatted: string, totalTimeElapsedMs: int, metadata: array<array-key, mixed>|null, queryCount: int|null, queryTimeMs: float|null, memoryDelta: int|null} $checkpoint
     * @return array{label: string, start: float, relative_start: float, end: float, relative_end: float, duration: float, duration_str: string, params: array<string, mixed>, collector: null}
     */
    private function buildMeasure(array $checkpoint, float $requestStart): array
    {
        $endSeconds = $checkpoint['totalTimeElapsedMs'] / 1000;
        $startSeconds = ($checkpoint['totalTimeElapsedMs'] - $checkpoint['timeSinceLastCheckpointMs']) / 1000;
        $durationSeconds = $checkpoint['timeSinceLastCheckpointMs'] / 1000;

        return [
            'label' => $checkpoint['label'],
            'start' => $requestStart + $startSeconds,
            'relative_start' => $startSeconds,
            'end' => $requestStart + $endSeconds,
            'relative_end' => $endSeconds,
            'duration' => $durationSeconds,
            'duration_str' => $checkpoint['timeSinceLastCheckpointFormatted'],
            'params' => $this->buildParams($checkpoint),
            'collector' => null,
        ];
    }

    /**
     * @param array{metadata: array<array-key, mixed>|null, queryCount: int|null, queryTimeMs: float|null, memoryDelta: int|null} $checkpoint
     * @return array<string, mixed>
     */
    private function buildParams(array $checkpoint): array
    {
        $params = [];

        if ($checkpoint['metadata'] !== null) {
            foreach ($checkpoint['metadata'] as $key => $value) {
                $params[(string) $key] = StopwatchCheckpoint::formatMetadataValue($value);
            }
        }

        if ($checkpoint['queryCount'] !== null) {
            $params['queries'] = "{$checkpoint['queryCount']}q / {$checkpoint['queryTimeMs']}ms";
        }

        if ($checkpoint['memoryDelta'] !== null) {
            $params['memory'] = StopwatchCheckpoint::formatMemoryDelta($checkpoint['memoryDelta']);
        }

        return $params;
    }

    /**
     * @return array<string, array{icon?: string, widget?: string, map?: string, default?: string, tooltip?: string}>
     */
    public function getWidgets(): array
    {
        return [
            'stopwatch' => [
                'icon' => 'clock-o',
                'tooltip' => 'Stopwatch',
                'widget' => 'PhpDebugBar.Widgets.TimelineWidget',
                'map' => 'stopwatch',
                'default' => '{}',
            ],
            'stopwatch:badge' => [
                'map' => 'stopwatch.duration_str',
                'default' => '',
            ],
        ];
    }
}
