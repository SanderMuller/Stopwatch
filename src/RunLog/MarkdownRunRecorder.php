<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use SanderMuller\Stopwatch\Stopwatch;
use Throwable;

/**
 * Default {@see RunRecorder} — persists each finished run as a markdown file
 * with YAML frontmatter under `storage/stopwatch/runs/<ULID>.md`.
 *
 * Filters:
 *  - `minDurationMs` — runs shorter than this are not written.
 *  - `skipEmpty`     — runs with zero checkpoints are not written.
 *
 * Detail levels:
 *  - `summary` — header + per-checkpoint table only (`Stopwatch::toMarkdown()` output).
 *  - `full`    — also appends per-call SQL and HTTP detail tables. SQL bindings are
 *                included only when `includeBindings=true` (PII opt-in).
 *
 * @phpstan-type FinalTotals array{duration_ms: float, checkpoints: int, queries_total: int|null, query_ms_total: float|null, http_total: int|null, http_ms_total: float|null, memory_delta_bytes: int|null, slow_threshold_ms: int, exceeds_slow_threshold: bool}
 */
final readonly class MarkdownRunRecorder implements RunRecorder
{
    private DetailRenderer $detailRenderer;

    public function __construct(
        private RunLogStore $store,
        private ?int $minDurationMs = 50,
        private int $maxFiles = 200,
        private int $maxAgeDays = 7,
        private string $detail = 'summary',
        bool $includeBindings = false,
        private bool $skipEmpty = true,
    ) {
        $this->detailRenderer = new DetailRenderer($includeBindings);
    }

    public function record(Stopwatch $stopwatch, array $context): void
    {
        try {
            $this->doRecord($stopwatch, $context);
        } catch (Throwable $throwable) {
            $this->reportFailure($throwable);
        }
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function doRecord(Stopwatch $stopwatch, array $context): void
    {
        $totals = $stopwatch->finalRunTotals();

        if ($this->shouldSkip($totals)) {
            return;
        }

        $id = (string) Str::ulid();

        $this->store->write($id, $this->buildContents($id, $stopwatch, $totals, $context));
        $this->store->pruneByCount($this->maxFiles);

        if (random_int(1, 100) <= 5) {
            $this->store->pruneByAge($this->maxAgeDays);
        }
    }

    /**
     * @param FinalTotals $totals
     */
    private function shouldSkip(array $totals): bool
    {
        if ($this->skipEmpty && $totals['checkpoints'] === 0) {
            return true;
        }

        return $this->minDurationMs !== null && $totals['duration_ms'] < $this->minDurationMs;
    }

    /**
     * @param FinalTotals $totals
     * @param array<string, scalar|null> $context
     */
    private function buildContents(string $id, Stopwatch $stopwatch, array $totals, array $context): string
    {
        $frontmatter = Frontmatter::format($this->frontmatterValues($id, $totals, $context));
        $body = $stopwatch->toMarkdown();
        $detail = $this->detail === 'full' ? $this->detailRenderer->render($stopwatch->checkpoints()) : '';

        return $frontmatter . "\n" . $body . $detail . "\n";
    }

    /**
     * @param FinalTotals $totals
     * @param array<string, scalar|null> $context
     * @return array<string, scalar|null>
     */
    private function frontmatterValues(string $id, array $totals, array $context): array
    {
        $values = [
            'id' => $id,
            'recorded_at' => CarbonImmutable::now()->format('Y-m-d\TH:i:s.vP'),
            'duration_ms' => round($totals['duration_ms'], 3),
            'checkpoints' => $totals['checkpoints'],
            'url' => $context['url'] ?? null,
            'method' => $context['method'] ?? null,
            'status' => $context['status'] ?? null,
            'command' => $context['command'] ?? null,
            'queries_total' => $totals['queries_total'],
            'query_ms_total' => $totals['query_ms_total'],
            'http_total' => $totals['http_total'],
            'http_ms_total' => $totals['http_ms_total'],
            'memory_delta_bytes' => $totals['memory_delta_bytes'],
            'slow_threshold_ms' => $totals['slow_threshold_ms'],
            'exceeds_slow_threshold' => $totals['exceeds_slow_threshold'],
        ];

        if (($context['threw'] ?? null) === true) {
            $values['threw'] = true;
        }

        return $values;
    }

    private function reportFailure(Throwable $e): void
    {
        try {
            if (function_exists('logger')) {
                logger()->warning('Stopwatch run recorder failed: ' . $e->getMessage(), ['exception' => $e]);
            }
        } catch (Throwable) {
            // logger unavailable — swallow silently rather than break the request.
        }
    }
}
