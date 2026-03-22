<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Exception;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\HtmlString;
use Stringable;

/**
 * @implements Arrayable<string, mixed>
 * @phpstan-ignore complexity.classLike
 */
final class Stopwatch implements Arrayable, Htmlable, Jsonable, Stringable
{
    private ?CarbonImmutable $startTime = null;

    private ?CarbonImmutable $endTime = null;

    private ?CarbonInterval $timeSinceLastCheckpoint = null;

    private StopwatchCheckpointCollection $checkpoints;

    private int $slowCheckpointThresholdMs = 50;

    private StopwatchOutput $output = StopwatchOutput::Silent;

    private ?string $logLevel = null;

    private bool $trackingQueries = false;

    private int $queryCount = 0;

    private float $queryDurationMs = 0;

    private bool $trackingMemory = false;

    private int $lastMemoryUsage = 0;

    private bool $enabled = true;

    private function __construct()
    {
        $this->checkpoints = StopwatchCheckpointCollection::empty();
    }

    public static function new(): self
    {
        return new self();
    }

    public function enable(): self
    {
        $this->enabled = true;

        return $this;
    }

    public function disable(): self
    {
        $this->enabled = false;

        return $this;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function start(): self
    {
        return $this->reset();
    }

    public function restart(): self
    {
        return $this->reset();
    }

    public function reset(): self
    {
        if (! $this->enabled) {
            return $this;
        }

        $this->checkpoints = StopwatchCheckpointCollection::empty();

        $this->startTime = CarbonImmutable::now();

        $this->endTime = null;

        $this->timeSinceLastCheckpoint = null;

        $this->queryCount = 0;
        $this->queryDurationMs = 0;

        if ($this->trackingMemory) {
            $this->lastMemoryUsage = memory_get_usage();
        }

        return $this;
    }

    public function started(): bool
    {
        return $this->startTime instanceof CarbonImmutable;
    }

    public function ended(): bool
    {
        return $this->endTime instanceof CarbonImmutable;
    }

    /**
     * @param array<array-key, mixed>|null $metadata
     */
    public function checkpoint(string $label, ?array $metadata = null, ?StopwatchOutput $output = null, ?string $logLevel = null): self
    {
        if (! $this->enabled || $this->ended()) {
            return $this;
        }

        if (! $this->started()) {
            $this->start();
        }

        if (! $this->startTime instanceof CarbonImmutable) {
            throw new Exception('Stopwatch has not been started properly.');
        }

        $now = CarbonImmutable::now();
        $lastCheckpoint = $this->checkpoints->lastCheckpoint();
        $previousTime = $lastCheckpoint instanceof StopwatchCheckpoint ? $lastCheckpoint->time : $this->startTime;

        $this->timeSinceLastCheckpoint = $previousTime->diffAsCarbonInterval($now, absolute: true)->cascade();

        $queryMetrics = $this->trackingQueries ? $this->collectQueryMetrics() : null;
        $memoryMetrics = $this->trackingMemory ? $this->collectMemoryMetrics() : null;

        $this->checkpoints->addCheckpoint(
            label: $label,
            metadata: $metadata,
            stopwatchStartTime: $this->startTime,
            timeSinceLastCheckpoint: $this->timeSinceLastCheckpoint,
            time: $now,
            queryCount: $queryMetrics['queries'] ?? null,
            queryTimeMs: $queryMetrics['query_time_ms'] ?? null,
            memoryUsage: $memoryMetrics['memory_usage'] ?? null,
            memoryDelta: $memoryMetrics['memory_delta'] ?? null,
            memoryPeak: $memoryMetrics['memory_peak'] ?? null,
        );

        if (($output ?? $this->output) !== StopwatchOutput::Silent) {
            $this->emitCheckpoint(
                metadata: $metadata,
                output: $output,
                logLevel: $logLevel,
            );
        }

        return $this;
    }

    public function timeSinceLastCheckpoint(): CarbonInterval
    {
        return $this->timeSinceLastCheckpoint ?? now()->diffAsCarbonInterval($this->startTime, absolute: true)->cascade();
    }

    /**
     * @alias
     * @param array<array-key, mixed>|null $metadata
     * @see self::checkpoint()
     */
    public function lap(string $label, ?array $metadata = null, ?StopwatchOutput $output = null, ?string $logLevel = null): self
    {
        return $this->checkpoint(
            label: $label,
            metadata: $metadata,
            output: $output,
            logLevel: $logLevel,
        );
    }

    /**
     * @param array<array-key, mixed>|null $metadata
     */
    public function log(string $label, ?string $level = null, ?array $metadata = null): self
    {
        return $this->checkpoint(
            label: $label,
            metadata: $metadata,
            output: StopwatchOutput::Log,
            logLevel: $level,
        );
    }

    public function outputTo(StopwatchOutput $output, ?string $logLevel = null): self
    {
        $this->output = $output;

        if ($logLevel !== null) {
            $this->logLevel = $logLevel;
        }

        return $this;
    }

    public function setLogLevel(?string $level): self
    {
        $this->logLevel = $level;

        return $this;
    }

    public function withQueryTracking(): self
    {
        if (! $this->enabled || $this->trackingQueries) {
            return $this;
        }

        if (! class_exists(QueryExecuted::class) || ! app()->bound(DatabaseManager::class)) {
            throw new Exception('Query tracking requires illuminate/database. Install it via: composer require illuminate/database');
        }

        $this->trackingQueries = true;
        $this->queryCount = 0;
        $this->queryDurationMs = 0;

        app(DatabaseManager::class)->connection()->listen(function (QueryExecuted $query): void {
            if (! $this->trackingQueries || $this->ended()) {
                return;
            }

            $this->queryCount++;
            $this->queryDurationMs += $query->time;
        });

        return $this;
    }

    public function withMemoryTracking(): self
    {
        if (! $this->enabled) {
            return $this;
        }

        $this->trackingMemory = true;
        $this->lastMemoryUsage = memory_get_usage();

        return $this;
    }

    /**
     * @return array{memory_usage: string, memory_delta: string, memory_peak: string}
     */
    private function collectMemoryMetrics(): array
    {
        $currentMemory = memory_get_usage();
        $delta = $currentMemory - $this->lastMemoryUsage;
        $this->lastMemoryUsage = $currentMemory;

        return [
            'memory_usage' => $this->formatBytes($currentMemory),
            'memory_delta' => ($delta >= 0 ? '+' : '') . $this->formatBytes($delta),
            'memory_peak' => $this->formatBytes(memory_get_peak_usage()),
        ];
    }

    private function formatBytes(int $bytes): string
    {
        $absBytes = abs($bytes);

        return match (true) {
            $absBytes >= 1048576 => round($bytes / 1048576, 1) . 'MB',
            $absBytes >= 1024 => round($bytes / 1024, 1) . 'KB',
            default => $bytes . 'B',
        };
    }

    /**
     * @return array{queries: int, query_time_ms: float}
     */
    private function collectQueryMetrics(): array
    {
        $metrics = [
            'queries' => $this->queryCount,
            'query_time_ms' => round($this->queryDurationMs, 1),
        ];

        $this->queryCount = 0;
        $this->queryDurationMs = 0;

        return $metrics;
    }

    /**
     * @param array<array-key, mixed>|null $metadata
     */
    private function emitCheckpoint(?array $metadata, ?StopwatchOutput $output = null, ?string $logLevel = null): void
    {
        /** @noinspection ForgottenDebugOutputInspection */
        match ($output ?? $this->output) {
            StopwatchOutput::Log => logger()->log($logLevel ?? $this->logLevel ?? 'debug', $this->lastCheckpointFormatted(), $metadata ?? []),
            StopwatchOutput::Stderr => fprintf(STDERR, "  %s\n", $this->lastCheckpointFormatted()),
            StopwatchOutput::Dump => dump($this->lastCheckpointFormatted()),
            StopwatchOutput::Silent => null,
        };
    }

    public function lastCheckpointFormatted(): string
    {
        return $this->checkpoints->lastCheckpoint()?->formattedPlainText() ?? '';
    }

    public function render(): HtmlString
    {
        return new HtmlString($this->toHtml());
    }

    public function slowCheckpointThreshold(int $ms): self
    {
        $this->slowCheckpointThresholdMs = $ms;

        return $this;
    }

    /**
     * Wrap a closure and create a checkpoint after execution.
     *
     * @template TReturn
     * @param (callable(): TReturn) $callback
     * @param array<array-key, mixed>|null $metadata
     * @return TReturn
     */
    public function measure(string $label, callable $callback, ?array $metadata = null, ?StopwatchOutput $output = null, ?string $logLevel = null): mixed
    {
        if (! $this->enabled) {
            return $callback();
        }

        if (! $this->started()) {
            $this->start();
        }

        $result = $callback();

        $this->checkpoint(
            label: $label,
            metadata: $metadata,
            output: $output,
            logLevel: $logLevel,
        );

        return $result;
    }

    public function finish(): self
    {
        if (! $this->enabled || $this->endTime instanceof CarbonImmutable) {
            return $this;
        }

        $this->endTime = CarbonImmutable::now();

        return $this;
    }

    /**
     * @alias
     * @see self::finish()
     */
    public function stop(): self
    {
        return $this->finish();
    }

    /**
     * @alias
     * @see self::finish()
     */
    public function end(): self
    {
        return $this->finish();
    }

    public function totalRunDuration(): CarbonInterval
    {
        if (! $this->started()) {
            return CarbonInterval::milliseconds(0);
        }

        if (! $this->startTime instanceof CarbonImmutable) {
            throw new Exception('Stopwatch has not been started properly.');
        }

        $endTime = $this->endTime ?? CarbonImmutable::now();

        return $this->startTime->diffAsCarbonInterval($endTime, absolute: true)->cascade();
    }

    public function totalRunDurationReadable(): string
    {
        $ms = round($this->totalRunDuration()->totalMilliseconds, 1);

        return "{$ms}ms";
    }

    public function timeSinceLastCheckpointReadable(): string
    {
        $lastCheckpoint = $this->checkpoints->lastCheckpoint();

        if (! $lastCheckpoint instanceof StopwatchCheckpoint) {
            return $this->totalRunDurationReadable();
        }

        $endTime = $this->endTime ?? CarbonImmutable::now();
        $ms = round($lastCheckpoint->time->diffInMilliseconds($endTime, absolute: true), 1);

        return "{$ms}ms";
    }

    public function dd(mixed ...$args): never
    {
        $this->finish();

        $this->dump(...$args);

        /** @noinspection ForgottenDebugOutputInspection */
        dd();
    }

    public function dump(mixed ...$args): self
    {
        /** @noinspection ForgottenDebugOutputInspection */
        dump($this, ...$args);

        return $this;
    }

    /**
     * Write a plain-text phase profile to stderr.
     *
     * Output format:
     *   [3ms / 3ms] Validation
     *   [25ms / 28ms] DB inserts
     *   Total: 28ms
     */
    public function toStderr(?string $title = null): self
    {
        $this->finish();

        if ($title !== null) {
            fprintf(STDERR, "%s\n", $title);
        }

        foreach ($this->checkpoints as $checkpoint) {
            fprintf(STDERR, "  %s\n", $checkpoint->formattedPlainText());
        }

        fprintf(STDERR, "  Total: %s\n", $this->totalRunDurationReadable());

        return $this;
    }

    public function toLog(?string $title = null, ?string $level = null): self
    {
        $this->finish();

        $level ??= $this->logLevel ?? 'debug';

        if ($title !== null) {
            logger()->log($level, $title);
        }

        foreach ($this->checkpoints as $checkpoint) {
            logger()->log($level, $checkpoint->formattedPlainText(), $checkpoint->metadata ?? []);
        }

        logger()->log($level, "Total: {$this->totalRunDurationReadable()}");

        return $this;
    }

    public function toHtml(): string
    {
        $this->finish();

        return <<<HTML
        <div style="width: 450px; background: white; box-shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1), 0 0 10px 4px rgb(0 0 0 / 0.08); border-radius: 0.7rem; margin: 15px 5px;">
            <header style="padding: 10px 15px; font-size: 16px;">
                <div style="display: flex; justify-content: space-between;">
                    <svg fill="#000000" height="24px" width="24px" xmlns="http://www.w3.org/2000/svg"
                             viewBox="0 0 488.7 488.7" xml:space="preserve">
                        <g>
                            <g>
                                <path d="M145.512,284.7c0,7-5.6,12.8-12.7,12.8c-3.5,0-6.7-1.4-9-3.7c-2.3-2.3-3.7-5.5-3.7-9c0-7,5.6-12.8,12.7-12.8
                                    C139.712,272,145.512,277.7,145.512,284.7z M154.012,348.2c-5,5-4.9,13,0,18l0,0c5,4.9,13.1,4.9,18-0.1c5-5,4.9-13.1-0.1-18
                                    C167.012,343.2,158.913,343.2,154.012,348.2z M235.313,194.5c7,0,12.8-5.7,12.8-12.8s-5.7-12.8-12.8-12.8s-12.8,5.7-12.8,12.8
                                    c0,3.5,1.4,6.7,3.7,9C228.613,193.1,231.813,194.5,235.313,194.5z M153.512,221.2c5,4.9,13.1,4.9,18-0.1l0.1-0.1
                                    c0.1-0.1,0.1-0.1,0.2-0.2c5-5,5-13,0-18s-13.1-5-18,0c-0.1,0.1-0.1,0.1-0.2,0.2c-0.1,0.1-0.1,0.1-0.2,0.2
                                    C148.512,208.1,148.512,216.2,153.512,221.2L153.512,221.2z M235.613,374.3c-7.1,0-12.7,5.7-12.7,12.8c0,3.5,1.4,6.7,3.7,9
                                    s5.5,3.7,9.1,3.7c7,0,12.7-5.7,12.7-12.8C248.413,380,242.712,374.3,235.613,374.3z M299.112,347.8c-5,5-5,13.1,0,18
                                    c5,5,13.1,5,18,0c5-5,4.9-13.1,0-18.1C312.112,342.8,304.013,342.8,299.112,347.8z M338.013,271.5c-7.1,0-12.8,5.7-12.8,12.8
                                    c0,3.5,1.4,6.7,3.7,9c2.3,2.3,5.5,3.8,9,3.7c7.1,0,12.7-5.7,12.8-12.8C350.813,277.2,345.112,271.5,338.013,271.5z M235.913,488.7
                                    c-112.5,0-204.1-91.6-204.1-204.1c0-104.4,78.9-190.7,180.2-202.6V51.1h-12.7c-6.4,0-11.5-5.2-11.5-11.5V11.5
                                    c0-6.4,5.2-11.5,11.5-11.5h73.2c6.4,0,11.5,5.2,11.5,11.5v28.1c0,6.4-5.2,11.5-11.5,11.5h-12.7v30.8
                                    c38.5,4.5,73.7,19.8,102.6,42.7l16.6-16.6l-1.5-1.5c-4.5-4.5-4.5-11.8,0-16.3l22.8-22.8c4.5-4.5,11.8-4.5,16.3,0l36.9,36.9
                                    c4.5,4.5,4.5,11.8,0,16.3l-22.8,22.8c-4.5,4.5-11.8,4.5-16.3,0l-1.5-1.5l-16.6,16.6c27.4,34.7,43.7,78.5,43.7,126
                                    C440.013,397.1,348.413,488.7,235.913,488.7z M392.612,284.6c0-86.4-70.3-156.7-156.7-156.7s-156.7,70.3-156.7,156.7
                                    s70.2,156.7,156.7,156.7C322.313,441.3,392.612,371,392.612,284.6z M317.913,201.8c4.7,4.7,5,12.3,0.7,17.3l-52,60.9
                                    c1.3,9.5-1.6,19.4-8.9,26.7c-12.3,12.3-32.3,12.3-44.7,0c-12.3-12.3-12.3-32.3,0-44.7c7.3-7.3,17.2-10.2,26.7-8.9l60.9-52
                                    C305.712,196.8,313.212,197.1,317.913,201.8L317.913,201.8z M244.413,275.4c-5-5-13.1-5-18,0c-5,5-5,13.1,0,18c5,5,13.1,5,18,0
                                    C249.413,288.4,249.413,280.4,244.413,275.4z"/>
                            </g>
                        </g>
                    </svg>

                    <span style="margin-left: auto; font-weight: bold; font-size: 18px; text-align: right; line-height: 0.9;">
                        {$this->totalRunDurationReadable()}<br/>

                        <span style="font-weight: normal; font-size: 12px; color: #888;">Total</span>
                    </span>
                </div>

                <p style="margin-bottom: 0; position: relative;">
                    {$this->startTime?->format('H:i:s.v')} - {$this->endTime?->format('H:i:s.v')}
                </p>
            </header>

            <div style="border-top: 1px solid rgb(243 244 246); border-bottom: 1px solid rgb(243 244 246); max-height: 60vh; overflow-y: auto;">
                {$this->checkpoints->render($this, $this->slowCheckpointThresholdMs)}
            </div>

            <footer style="padding: 10px 15px; display: flex; justify-content: space-between; align-items: center; font-size: 16px;">
                <span style="font-size: 14px; color: #888;">
                    +{$this->timeSinceLastCheckpointReadable()} after last checkpoint
                </span>

                <span style="font-weight: bold; font-size: 18px; text-align: right; line-height: 0.9;">
                    {$this->totalRunDurationReadable()}<br/>

                    <span style="font-weight: normal; font-size: 12px; color: #888;">Total</span>
                </span>
            </footer>
        </div>
        HTML;
    }

    public function toString(): string
    {
        return $this->totalRunDurationReadable();
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * @return array{
     *     startTime: non-falsy-string|null,
     *     endTime: non-falsy-string|null,
     *     checkpoints: array<array-key, mixed>,
     *     totalRunDuration: string,
     *     totalRunDurationMs: int,
     * }
     */
    public function toArray(): array
    {
        $this->finish();

        return [
            'startTime' => $this->startTime?->format('H:i:s.u'),
            'endTime' => $this->endTime?->format('H:i:s.u'),
            'checkpoints' => $this->checkpoints->toArray(),
            'totalRunDuration' => $this->totalRunDurationReadable(),
            'totalRunDurationMs' => (int) round($this->totalRunDuration()->totalMilliseconds),
        ];
    }

    public function toJson(mixed $options = 0): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | $options);
    }

    public function toServerTiming(): string
    {
        $this->finish();

        $metrics = [];

        foreach ($this->checkpoints as $checkpoint) {
            $name = preg_replace('/[^a-zA-Z0-9_-]/', '-', $checkpoint->label) ?? $checkpoint->label;
            $dur = round($checkpoint->timeSinceLastCheckpoint->totalMilliseconds, 1);

            $desc = addcslashes($checkpoint->label, '"\\');
            $metrics[] = "{$name};dur={$dur};desc=\"{$desc}\"";
        }

        $totalMs = round($this->totalRunDuration()->totalMilliseconds, 1);
        $metrics[] = "total;dur={$totalMs};desc=\"Total\"";

        return implode(', ', $metrics);
    }
}
