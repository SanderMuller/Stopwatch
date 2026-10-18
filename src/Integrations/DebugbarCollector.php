<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Integrations;

use Carbon\CarbonImmutable;
use DebugBar\DataCollector\DataCollector;
use DebugBar\DataCollector\Renderable;
use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\StopwatchCheckpoint;

final class DebugbarCollector extends DataCollector implements Renderable
{
    public const string NAME = 'stopwatch';

    public function __construct(
        private readonly Stopwatch $stopwatch,
    ) {}

    public function getName(): string
    {
        return self::NAME;
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
        // Debugbar collects after the response, so the timeline shows the finished run.
        $this->stopwatch->finish();

        $requestStart = $this->resolveRequestStart();
        $totalMs = $this->stopwatch->totalRunDuration()->totalMilliseconds;
        $totalSeconds = $totalMs / 1000;

        $measures = [];

        foreach ($this->stopwatch->checkpoints() as $stopwatchCheckpoint) {
            $measures[] = $this->buildMeasure($stopwatchCheckpoint, $requestStart);
        }

        return [
            'start' => $requestStart,
            'end' => $requestStart + $totalSeconds,
            'duration' => $totalSeconds,
            'duration_str' => round($totalMs, 1) . 'ms',
            'measures' => $measures,
        ];
    }

    private function resolveRequestStart(): float
    {
        $startTime = $this->stopwatch->startTime();

        if ($startTime instanceof CarbonImmutable) {
            return (float) $startTime->format('U.u');
        }

        if (defined('LARAVEL_START')) {
            $laravelStart = constant('LARAVEL_START');

            if (is_numeric($laravelStart)) {
                return (float) $laravelStart;
            }
        }

        return microtime(true);
    }

    /**
     * @return array{label: string, start: float, relative_start: float, end: float, relative_end: float, duration: float, duration_str: string, params: array<string, mixed>, collector: null}
     */
    private function buildMeasure(StopwatchCheckpoint $checkpoint, float $requestStart): array
    {
        $endMs = $checkpoint->timeSinceStopwatchStart->totalMilliseconds;
        $durationMs = $checkpoint->timeSinceLastCheckpoint->totalMilliseconds;
        $endSeconds = $endMs / 1000;
        $startSeconds = ($endMs - $durationMs) / 1000;
        $durationSeconds = $durationMs / 1000;

        return [
            'label' => $checkpoint->label,
            'start' => $requestStart + $startSeconds,
            'relative_start' => $startSeconds,
            'end' => $requestStart + $endSeconds,
            'relative_end' => $endSeconds,
            'duration' => $durationSeconds,
            'duration_str' => $checkpoint->timeSinceLastCheckpointFormatted,
            'params' => $this->buildParams($checkpoint),
            'collector' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildParams(StopwatchCheckpoint $checkpoint): array
    {
        $params = [];

        if ($checkpoint->metadata !== null) {
            foreach ($checkpoint->metadata as $key => $value) {
                $params[(string) $key] = StopwatchCheckpoint::formatMetadataValue($value, StopwatchCheckpoint::METADATA_DISPLAY_MAX_CHARS);
            }
        }

        if ($checkpoint->queryCount !== null) {
            $params['queries'] = "{$checkpoint->queryCount}q / {$checkpoint->queryTimeMs}ms";
        }

        if ($checkpoint->httpCount !== null) {
            $params['http'] = "{$checkpoint->httpCount}h / {$checkpoint->httpTimeMs}ms";
        }

        if ($checkpoint->memoryDelta !== null) {
            $params['memory'] = StopwatchCheckpoint::formatMemoryDelta($checkpoint->memoryDelta);
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
