<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Exception;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Traits\Conditionable;
use SanderMuller\Stopwatch\Notifications\StopwatchNotificationChannel;
use Stringable;

/**
 * @implements Arrayable<string, mixed>
 * @phpstan-ignore complexity.classLike
 */
final class Stopwatch implements Arrayable, Htmlable, Jsonable, Stringable
{
    use Conditionable;

    private ?CarbonImmutable $startTime = null;

    private ?CarbonImmutable $endTime = null;

    private ?int $startHrtime = null;

    private ?int $endHrtime = null;

    private ?int $lastCheckpointHrtime = null;

    private ?float $timeSinceLastCheckpointMs = null;

    private StopwatchCheckpointCollection $checkpoints;

    private int $slowCheckpointThresholdMs = 50;

    private StopwatchOutput $output = StopwatchOutput::Silent;

    private ?string $logLevel = null;

    private bool $trackingQueries = false;

    private bool $queryListenerRegistered = false;

    private int $queryCount = 0;

    private float $queryDurationMs = 0;

    /** @var list<array{sql: string, bindings: array<array-key, mixed>, durationMs: float}> */
    private array $queryCalls = [];

    /** Cap on stored per-checkpoint query detail rows; the count + total time still reflect every query. */
    private const int QUERY_CALL_DETAIL_CAP = 50;

    private bool $trackingMemory = false;

    private int $lastMemoryUsage = 0;

    private bool $trackingHttp = false;

    private bool $httpListenerRegistered = false;

    private int $httpCount = 0;

    private float $httpDurationMs = 0;

    /** @var list<array{method: string, url: string, status: int, durationMs: float}> */
    private array $httpCalls = [];

    /**
     * Per-request hrtime stamps keyed by spl_object_id of the Request, used to recover
     * elapsed time on ConnectionFailed (which carries no transferStats).
     *
     * @var array<int, int>
     */
    private array $httpRequestStarts = [];

    /** Cap on stored per-checkpoint HTTP call detail rows; the count + total time still reflect every call. */
    private const int HTTP_CALL_DETAIL_CAP = 50;

    /** @var array<StopwatchNotificationChannel|class-string<StopwatchNotificationChannel>> */
    private array $notificationChannels = [];

    private ?float $notifyThresholdMs = null;

    private bool $enabled = true;

    private function __construct(
        private readonly Clock $clock = new SystemClock(),
    ) {
        $this->checkpoints = StopwatchCheckpointCollection::empty();
    }

    public static function new(?Clock $clock = null): self
    {
        return new self($clock ?? new SystemClock());
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

        $this->startTime = $this->clock->now();
        $this->startHrtime = $this->clock->hrtime();

        $this->endTime = null;
        $this->endHrtime = null;

        $this->lastCheckpointHrtime = null;
        $this->timeSinceLastCheckpointMs = null;

        $this->queryCount = 0;
        $this->queryDurationMs = 0;
        $this->queryCalls = [];

        $this->httpCount = 0;
        $this->httpDurationMs = 0;
        $this->httpCalls = [];
        $this->httpRequestStarts = [];

        if ($this->trackingMemory) {
            $this->lastMemoryUsage = memory_get_usage();
        }

        return $this;
    }

    public function startTime(): ?CarbonImmutable
    {
        return $this->startTime;
    }

    public function started(): bool
    {
        return $this->startHrtime !== null;
    }

    public function ended(): bool
    {
        return $this->endHrtime !== null;
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

        if ($this->startHrtime === null) {
            throw new Exception('Stopwatch has not been started properly.');
        }

        $nowHrtime = $this->clock->hrtime();
        $now = $this->clock->now();

        $timeSinceLastCheckpointMs = ($nowHrtime - ($this->lastCheckpointHrtime ?? $this->startHrtime)) / 1_000_000;
        $timeSinceStopwatchStartMs = ($nowHrtime - $this->startHrtime) / 1_000_000;

        $this->timeSinceLastCheckpointMs = $timeSinceLastCheckpointMs;
        $this->lastCheckpointHrtime = $nowHrtime;

        $queryMetrics = $this->trackingQueries ? $this->collectQueryMetrics() : null;
        $memoryMetrics = $this->trackingMemory ? $this->collectMemoryMetrics() : null;
        $httpMetrics = $this->trackingHttp ? $this->collectHttpMetrics() : null;

        $this->checkpoints->addCheckpoint(
            label: $label,
            metadata: $metadata,
            timeSinceLastCheckpointMs: $timeSinceLastCheckpointMs,
            timeSinceStopwatchStartMs: $timeSinceStopwatchStartMs,
            time: $now,
            queryCount: $queryMetrics['queries'] ?? null,
            queryTimeMs: $queryMetrics['query_time_ms'] ?? null,
            memoryUsage: $memoryMetrics['memory_usage'] ?? null,
            memoryDelta: $memoryMetrics['memory_delta'] ?? null,
            memoryPeak: $memoryMetrics['memory_peak'] ?? null,
            httpCount: $httpMetrics['count'] ?? null,
            httpTimeMs: $httpMetrics['time_ms'] ?? null,
            httpCalls: $httpMetrics['calls'] ?? null,
            queryCalls: $queryMetrics['calls'] ?? null,
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
        if ($this->timeSinceLastCheckpointMs !== null) {
            return CarbonInterval::milliseconds($this->timeSinceLastCheckpointMs)->cascade();
        }

        if ($this->startHrtime === null) {
            return CarbonInterval::milliseconds(0)->cascade();
        }

        $ms = ($this->clock->hrtime() - $this->startHrtime) / 1_000_000;

        return CarbonInterval::milliseconds($ms)->cascade();
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
        if (! $this->enabled) {
            return $this;
        }

        if (! class_exists(QueryExecuted::class) || ! app()->bound(DatabaseManager::class)) {
            throw new Exception('Query tracking requires illuminate/database. Install it via: composer require illuminate/database');
        }

        $this->trackingQueries = true;
        $this->queryCount = 0;
        $this->queryDurationMs = 0;
        $this->queryCalls = [];

        if (! $this->queryListenerRegistered) {
            $this->queryListenerRegistered = true;

            app(DatabaseManager::class)->connection()->listen(function (QueryExecuted $query): void {
                if (! $this->enabled || ! $this->trackingQueries || $this->ended()) {
                    return;
                }

                $this->queryCount++;
                $this->queryDurationMs += $query->time;

                if (count($this->queryCalls) < self::QUERY_CALL_DETAIL_CAP) {
                    $this->queryCalls[] = [
                        'sql' => $query->sql,
                        'bindings' => $query->bindings,
                        'durationMs' => $query->time,
                    ];
                }
            });
        }

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
     * Track outbound HTTP requests made through Laravel's `Http::` facade. Direct
     * `new GuzzleHttp\Client` calls bypass the event dispatcher and will NOT be
     * captured — same limitation as Laravel Telescope.
     */
    public function withHttpTracking(): self
    {
        if (! $this->enabled) {
            return $this;
        }

        if (! class_exists(ResponseReceived::class) || ! app()->bound(Dispatcher::class)) {
            throw new Exception('HTTP tracking requires illuminate/http and a Laravel application container. Install it via: composer require illuminate/http');
        }

        $this->trackingHttp = true;
        $this->httpCount = 0;
        $this->httpDurationMs = 0;
        $this->httpCalls = [];
        $this->httpRequestStarts = [];

        if (! $this->httpListenerRegistered) {
            $this->httpListenerRegistered = true;
            $this->registerHttpListeners();
        }

        return $this;
    }

    private function registerHttpListeners(): void
    {
        $dispatcher = app(Dispatcher::class);

        $dispatcher->listen(RequestSending::class, function (RequestSending $event): void {
            if (! $this->shouldRecordHttp()) {
                return;
            }

            $this->httpRequestStarts[spl_object_id($event->request)] = $this->clock->hrtime();
        });

        $dispatcher->listen(ResponseReceived::class, function (ResponseReceived $event): void {
            if (! $this->shouldRecordHttp()) {
                return;
            }

            // Prefer Guzzle's transferStats (Telescope's pattern); fall back to wall-clock from RequestSending
            // when transferStats is missing (e.g. Http::fake()) or zero (under tests with FakeClock).
            $transferMs = $this->resolveTransferTimeMs($event->response);
            $durationMs = $transferMs > 0
                ? $transferMs
                : $this->consumeRequestStartElapsedMs($event->request);

            $this->recordHttpCall(
                method: $event->request->method(),
                url: $this->stripUrlQueryString($event->request->url()),
                status: $event->response->status(),
                durationMs: $durationMs,
            );
        });

        $dispatcher->listen(ConnectionFailed::class, function (ConnectionFailed $event): void {
            if (! $this->shouldRecordHttp()) {
                return;
            }

            $this->recordHttpCall(
                method: $event->request->method(),
                url: $this->stripUrlQueryString($event->request->url()),
                status: 0,
                durationMs: $this->consumeRequestStartElapsedMs($event->request),
            );
        });
    }

    private function shouldRecordHttp(): bool
    {
        return $this->enabled && $this->trackingHttp && ! $this->ended();
    }

    private function resolveTransferTimeMs(HttpResponse $response): float
    {
        $seconds = $response->transferStats?->getTransferTime() ?? 0.0;

        return floor($seconds * 1000);
    }

    /**
     * Match a response/failure event back to its RequestSending start hrtime by spl_object_id;
     * returns 0.0 if the start wasn't recorded (e.g. the request fired before withHttpTracking()
     * was enabled).
     */
    private function consumeRequestStartElapsedMs(Request $request): float
    {
        $key = spl_object_id($request);
        $startedAt = $this->httpRequestStarts[$key] ?? null;
        unset($this->httpRequestStarts[$key]);

        if ($startedAt === null) {
            return 0.0;
        }

        return ($this->clock->hrtime() - $startedAt) / 1_000_000;
    }

    /**
     * Strip the `?query=string` portion of a URL at capture time so secrets in URLs (api tokens,
     * session ids) don't leak through `toArray()` / `toJson()` / notifications. The HTML render
     * already redacted these for display, but downstream consumers got the raw URL.
     */
    private function stripUrlQueryString(string $url): string
    {
        $pos = strpos($url, '?');

        return $pos === false ? $url : substr($url, 0, $pos);
    }

    private function recordHttpCall(string $method, string $url, int $status, float $durationMs): void
    {
        $this->httpCount++;
        $this->httpDurationMs += $durationMs;

        if (count($this->httpCalls) < self::HTTP_CALL_DETAIL_CAP) {
            $this->httpCalls[] = [
                'method' => $method,
                'url' => $url,
                'status' => $status,
                'durationMs' => $durationMs,
            ];
        }
    }

    /**
     * @return array{memory_usage: int, memory_delta: int, memory_peak: int}
     */
    private function collectMemoryMetrics(): array
    {
        $currentMemory = memory_get_usage();
        $delta = $currentMemory - $this->lastMemoryUsage;
        $this->lastMemoryUsage = $currentMemory;

        return [
            'memory_usage' => $currentMemory,
            'memory_delta' => $delta,
            'memory_peak' => memory_get_peak_usage(),
        ];
    }

    /**
     * @return array{queries: int, query_time_ms: float, calls: list<array{sql: string, bindings: array<array-key, mixed>, durationMs: float}>}
     */
    private function collectQueryMetrics(): array
    {
        $metrics = [
            'queries' => $this->queryCount,
            'query_time_ms' => round($this->queryDurationMs, 1),
            'calls' => $this->queryCalls,
        ];

        $this->queryCount = 0;
        $this->queryDurationMs = 0;
        $this->queryCalls = [];

        return $metrics;
    }

    /**
     * @return array{count: int, time_ms: float, calls: list<array{method: string, url: string, status: int, durationMs: float}>}
     */
    private function collectHttpMetrics(): array
    {
        $metrics = [
            'count' => $this->httpCount,
            'time_ms' => round($this->httpDurationMs, 1),
            'calls' => $this->httpCalls,
        ];

        $this->httpCount = 0;
        $this->httpDurationMs = 0;
        $this->httpCalls = [];
        // Don't clear $httpRequestStarts here — pool/async requests may be in-flight across the checkpoint
        // and we still need their RequestSending hrtime when the response/failure event arrives. Stale
        // entries are cleared on reset() (i.e. next start()/restart()), which fires per request in
        // typical Laravel use, so orphan accumulation is bounded.

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
        if (! $this->enabled || $this->startHrtime === null || $this->endHrtime !== null) {
            return $this;
        }

        $this->endTime = $this->clock->now();
        $this->endHrtime = $this->clock->hrtime();

        $this->dispatchNotifications();

        return $this;
    }

    private function dispatchNotifications(): void
    {
        if ($this->notifyThresholdMs === null || $this->notificationChannels === [] || $this->startHrtime === null || $this->endHrtime === null) {
            return;
        }

        $totalMs = ($this->endHrtime - $this->startHrtime) / 1_000_000;

        if ($totalMs < $this->notifyThresholdMs) {
            return;
        }

        // Channels may call toLog()/toHtml() which call finish() — this is safe
        // because endHrtime is already set, so finish() will no-op.
        foreach ($this->notificationChannels as $notificationChannel) {
            if (is_string($notificationChannel)) {
                $notificationChannel = app($notificationChannel);
            }

            $notificationChannel->notify($this);
        }
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
        if ($this->startHrtime === null) {
            return CarbonInterval::milliseconds(0)->cascade();
        }

        $endHrtime = $this->endHrtime ?? $this->clock->hrtime();
        $ms = ($endHrtime - $this->startHrtime) / 1_000_000;

        return CarbonInterval::milliseconds($ms)->cascade();
    }

    public function totalRunDurationReadable(): string
    {
        return self::formatDuration($this->totalRunDuration()->totalMilliseconds);
    }

    /**
     * Compact human-readable duration. Scales unit so long profiles read clearly.
     *   3.4 → "3.4ms", 142.7 → "143ms", 1247 → "1.25s", 65000 → "1m 5s"
     */
    public static function formatDuration(float $ms): string
    {
        // Boundaries account for the rounding the next unit will apply: e.g. 999.6ms rounds
        // to "1000ms" within the ms branch, so we promote it to seconds (and similarly for
        // 59_995ms+ which would round up to "60s" within the seconds branch).
        if ($ms >= 59_995) {
            $totalSeconds = (int) round($ms / 1000);
            $minutes = intdiv($totalSeconds, 60);
            $seconds = $totalSeconds % 60;

            return $minutes . 'm ' . $seconds . 's';
        }

        if ($ms >= 999.5) {
            return round($ms / 1000, 2) . 's';
        }

        if ($ms >= 99.95) {
            return (int) round($ms) . 'ms';
        }

        return round($ms, 1) . 'ms';
    }

    public function timeSinceLastCheckpointReadable(): string
    {
        if ($this->lastCheckpointHrtime === null) {
            return $this->totalRunDurationReadable();
        }

        $endHrtime = $this->endHrtime ?? $this->clock->hrtime();

        return self::formatDuration(($endHrtime - $this->lastCheckpointHrtime) / 1_000_000);
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

    /**
     * @param array<StopwatchNotificationChannel|class-string<StopwatchNotificationChannel>> $channels
     */
    public function notifyUsing(array $channels): self
    {
        $this->notificationChannels = $channels;

        return $this;
    }

    public function notifyIfSlowerThan(int|CarbonInterval $threshold): self
    {
        $this->notifyThresholdMs = $threshold instanceof CarbonInterval
            ? $threshold->totalMilliseconds
            : (float) $threshold;

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

        return StopwatchHtmlRenderer::render(
            startLabel: e($this->startTime?->format('H:i:s.v') ?? ''),
            endLabel: e($this->endTime?->format('H:i:s.v') ?? ''),
            totalMs: $this->totalRunDuration()->totalMilliseconds,
            totalLabel: $this->totalRunDurationReadable(),
            checkpoints: $this->checkpoints,
            slowThresholdMs: $this->slowCheckpointThresholdMs,
            tail: '+' . $this->timeSinceLastCheckpointReadable() . ' after last checkpoint',
            markdown: $this->toMarkdown(),
        );
    }

    /**
     * Markdown summary of the profile, suitable for pasting into an AI chat or a bug report:
     * a header block with totals + a per-checkpoint table.
     */
    public function toMarkdown(): string
    {
        $this->finish();

        $totals = $this->checkpoints->totals();

        return implode("\n", [
            ...$this->markdownSummary($totals),
            '',
            ...$this->markdownTable($totals),
        ]);
    }

    /**
     * @param array{queries: int, queryMs: float, memoryDelta: int, httpCount: int, httpMs: float, hasQueries: bool, hasMemory: bool, hasHttp: bool} $totals
     * @return list<string>
     */
    private function markdownSummary(array $totals): array
    {
        $lines = ['# Stopwatch profile', ''];
        $lines[] = '- **Total:** ' . $this->totalRunDurationReadable();
        $lines[] = '- **Checkpoints:** ' . $this->checkpoints->count();
        if ($this->startTime instanceof CarbonImmutable && $this->endTime instanceof CarbonImmutable) {
            $lines[] = '- **Window:** ' . $this->startTime->format('H:i:s.v') . ' → ' . $this->endTime->format('H:i:s.v');
        }

        $lines[] = '- **Slow threshold:** ' . $this->slowCheckpointThresholdMs . 'ms';
        if ($totals['hasQueries']) {
            $lines[] = '- **Queries (total):** ' . $totals['queries'] . ' in ' . self::formatDuration($totals['queryMs']);
        }

        if ($totals['hasHttp']) {
            $lines[] = '- **HTTP calls (total):** ' . $totals['httpCount'] . ' in ' . self::formatDuration($totals['httpMs']);
        }

        if ($totals['hasMemory']) {
            $lines[] = '- **Memory delta (total):** ' . StopwatchCheckpoint::formatMemoryDelta($totals['memoryDelta']);
        }

        return $lines;
    }

    /**
     * @param array{queries: int, queryMs: float, memoryDelta: int, httpCount: int, httpMs: float, hasQueries: bool, hasMemory: bool, hasHttp: bool} $totals
     * @return list<string>
     */
    private function markdownTable(array $totals): array
    {
        $totalMs = $this->totalRunDuration()->totalMilliseconds;
        $hasQ = $totals['hasQueries'];
        $hasM = $totals['hasMemory'];
        $hasH = $totals['hasHttp'];

        $headers = ['#', 'Checkpoint', 'Δ', 'Cumulative', 'Share', 'Slow'];
        if ($hasQ) {
            $headers[] = 'Queries';
        }

        if ($hasH) {
            $headers[] = 'HTTP';
        }

        if ($hasM) {
            $headers[] = 'Memory Δ';
        }

        $headers[] = 'Metadata';

        $lines = [
            '| ' . implode(' | ', $headers) . ' |',
            '|' . str_repeat(' --- |', count($headers)),
        ];

        $idx = 0;
        foreach ($this->checkpoints as $checkpoint) {
            $idx++;
            $lines[] = '| ' . implode(' | ', $this->markdownRow($checkpoint, $idx, $totalMs, $hasQ, $hasM, $hasH)) . ' |';
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function markdownRow(StopwatchCheckpoint $cp, int $idx, float $totalMs, bool $hasQ, bool $hasM, bool $hasH): array
    {
        $delta = $cp->timeSinceLastCheckpoint->totalMilliseconds;
        $cum = (int) round($cp->timeSinceStopwatchStart->totalMilliseconds);
        $share = $totalMs > 0 ? round(($delta / $totalMs) * 100, 1) : 0;
        $row = [
            (string) $idx,
            $this->escapeMarkdownCell($cp->label),
            self::formatDuration($delta),
            self::formatDuration($cum),
            $share . '%',
            $delta >= $this->slowCheckpointThresholdMs
                ? round($delta / max(1, $this->slowCheckpointThresholdMs), 1) . '×'
                : '',
        ];
        if ($hasQ) {
            $row[] = $this->markdownQueryCell($cp);
        }

        if ($hasH) {
            $row[] = $this->markdownHttpCell($cp);
        }

        if ($hasM) {
            $row[] = $this->markdownMemoryCell($cp);
        }

        $row[] = $cp->metadata !== null
            ? $this->escapeMarkdownCell((string) json_encode($cp->metadata, JSON_UNESCAPED_SLASHES))
            : '';

        return $row;
    }

    private function markdownQueryCell(StopwatchCheckpoint $cp): string
    {
        if ($cp->queryCount === null) {
            return '';
        }

        return $cp->queryCount . 'q in ' . self::formatDuration($cp->queryTimeMs ?? 0);
    }

    private function markdownHttpCell(StopwatchCheckpoint $cp): string
    {
        if ($cp->httpCount === null) {
            return '';
        }

        return $cp->httpCount . 'h in ' . self::formatDuration($cp->httpTimeMs ?? 0);
    }

    private function markdownMemoryCell(StopwatchCheckpoint $cp): string
    {
        if ($cp->memoryDelta === null) {
            return '';
        }

        return StopwatchCheckpoint::formatMemoryDelta($cp->memoryDelta);
    }

    private function escapeMarkdownCell(string $value): string
    {
        return str_replace(['|', "\n", "\r"], ['\\|', ' ', ''], $value);
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

        $totalMs = $this->totalRunDuration()->totalMilliseconds;

        return [
            'startTime' => $this->startTime?->format('H:i:s.u'),
            'endTime' => $this->endTime?->format('H:i:s.u'),
            'checkpoints' => $this->checkpoints->toArray(),
            'totalRunDuration' => round($totalMs, 1) . 'ms',
            'totalRunDurationMs' => (int) round($totalMs),
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
