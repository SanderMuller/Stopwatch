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
 * Optional collectors (gated on flags + non-null collaborators):
 *  - `collectExceptions` — when a captured `Throwable` is in transient context, persist
 *                          its class/file/line into frontmatter and a `## Exception`
 *                          body section via {@see ExceptionDetailRenderer}.
 *  - `collectContext`    — capture `Illuminate\Support\Facades\Context::all()` into a
 *                          `## Context` body section via {@see ContextCapture} +
 *                          {@see ContextCaptureRenderer}; promoted keys land in
 *                          frontmatter as `ctx_<key>` lines.
 *
 * Body section ordering: `Stopwatch::toMarkdown()` → `## SQL detail` (full) →
 * `## HTTP detail` (full) → `## Exception` → `## Context`.
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
        private bool $collectExceptions = true,
        private ExceptionDetailRenderer $exceptionRenderer = new ExceptionDetailRenderer(new ExceptionDetail()),
        private bool $collectContext = false,
        private ContextCapture $contextCapture = new ContextCapture(),
        private ContextCaptureRenderer $contextRenderer = new ContextCaptureRenderer(),
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
     * @param array<string, scalar|null> $runContext
     */
    private function doRecord(Stopwatch $stopwatch, array $runContext): void
    {
        $totals = $stopwatch->finalRunTotals();

        if ($this->shouldSkip($totals)) {
            return;
        }

        $id = (string) Str::ulid();
        $exception = $this->resolveException($stopwatch);
        $capturedContext = $this->resolveContext();

        $this->store->write($id, $this->buildContents($id, $stopwatch, $totals, $runContext, $exception, $capturedContext));
        $this->store->pruneByCount($this->maxFiles);

        if (random_int(1, 100) <= 5) {
            $this->store->pruneByAge($this->maxAgeDays);
        }
    }

    private function resolveException(Stopwatch $stopwatch): ?Throwable
    {
        if (! $this->collectExceptions) {
            return null;
        }

        $value = $stopwatch->transientContext(Stopwatch::TRANSIENT_EXCEPTION);

        return $value instanceof Throwable ? $value : null;
    }

    /**
     * @return array{frontmatter_lines: list<string>, body: array<string, string>}
     */
    private function resolveContext(): array
    {
        if (! $this->collectContext) {
            return ['frontmatter_lines' => [], 'body' => []];
        }

        return $this->contextCapture->capture();
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
     * @param array<string, scalar|null> $runContext
     * @param array{frontmatter_lines: list<string>, body: array<string, string>} $capturedContext
     */
    private function buildContents(
        string $id,
        Stopwatch $stopwatch,
        array $totals,
        array $runContext,
        ?Throwable $exception,
        array $capturedContext,
    ): string {
        $frontmatterValues = $this->frontmatterValues($id, $totals, $runContext, $exception);
        $frontmatter = Frontmatter::format($frontmatterValues, $capturedContext['frontmatter_lines']);

        $body = $stopwatch->toMarkdown();
        $body .= $this->detail === 'full' ? $this->detailRenderer->render($stopwatch->checkpoints()) : '';

        if ($exception instanceof Throwable) {
            $body .= "\n\n" . $this->exceptionRenderer->render($exception);
        }

        $contextSection = $this->contextRenderer->render($capturedContext['body']);

        if ($contextSection !== '') {
            $body .= "\n\n" . $contextSection;
        }

        return $frontmatter . "\n" . $body . "\n";
    }

    /**
     * @param FinalTotals $totals
     * @param array<string, scalar|null> $runContext
     * @return array<string, scalar|null>
     */
    private function frontmatterValues(string $id, array $totals, array $runContext, ?Throwable $exception): array
    {
        $values = [
            'id' => $id,
            'recorded_at' => CarbonImmutable::now()->format('Y-m-d\TH:i:s.vP'),
            'duration_ms' => round($totals['duration_ms'], 3),
            'checkpoints' => $totals['checkpoints'],
            'url' => $runContext['url'] ?? null,
            'method' => $runContext['method'] ?? null,
            'status' => $runContext['status'] ?? null,
            'command' => $runContext['command'] ?? null,
            'queries_total' => $totals['queries_total'],
            'query_ms_total' => $totals['query_ms_total'],
            'http_total' => $totals['http_total'],
            'http_ms_total' => $totals['http_ms_total'],
            'memory_delta_bytes' => $totals['memory_delta_bytes'],
            'slow_threshold_ms' => $totals['slow_threshold_ms'],
            'exceeds_slow_threshold' => $totals['exceeds_slow_threshold'],
        ];

        if (($runContext['threw'] ?? null) === true) {
            $values['threw'] = true;
        }

        if ($exception instanceof Throwable) {
            $values['exception_class'] = $exception::class;
            $values['exception_file'] = PathRelativiser::relativise($exception->getFile());
            $values['exception_line'] = $exception->getLine();
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
