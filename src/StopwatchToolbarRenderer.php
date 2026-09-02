<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

/**
 * @phpstan-type FinalRunTotals array{
 *     duration_ms: float,
 *     checkpoints: int,
 *     queries_total: int|null,
 *     query_ms_total: float|null,
 *     http_total: int|null,
 *     http_ms_total: float|null,
 *     memory_delta_bytes: int|null,
 *     slow_threshold_ms: int,
 *     exceeds_slow_threshold: bool,
 * }
 */
final readonly class StopwatchToolbarRenderer
{
    private const array ALLOWED_POSITIONS = ['bottom-right', 'bottom-left', 'top-right', 'top-left'];

    public function __construct(
        private Stopwatch $stopwatch,
    ) {}

    public function render(): string
    {
        $totals = $this->stopwatch->finalRunTotals();
        $isSlow = $totals['duration_ms'] >= $this->slowThresholdMs();

        $html = StopwatchInjector::MARKER . "\n";
        $html .= '<style>' . $this->css() . '</style>';
        $html .= '<details id="stopwatch-toolbar" class="sw-pos-' . $this->position() . '">';
        $html .= '<summary class="sw-summary">';
        $html .= $this->summaryPills($totals, $isSlow);
        $html .= '</summary>';
        $html .= '<div class="sw-panel">';
        $html .= $this->panelTable($this->stopwatch->checkpoints(), $totals['duration_ms']);

        return $html . '</div></details>';
    }

    private function position(): string
    {
        $raw = config('stopwatch.inject.position', 'bottom-right');
        $value = is_string($raw) ? $raw : 'bottom-right';

        return in_array($value, self::ALLOWED_POSITIONS, true) ? $value : 'bottom-right';
    }

    private function slowThresholdMs(): int
    {
        $raw = config('stopwatch.inject.slow_request_threshold_ms', 500);

        if (is_int($raw)) {
            return $raw;
        }

        if (is_numeric($raw)) {
            return (int) $raw;
        }

        return 500;
    }

    /** @param FinalRunTotals $totals */
    private function summaryPills(array $totals, bool $isSlow): string
    {
        $durationClass = $isSlow ? 'sw-pill sw-pill-slow' : 'sw-pill';
        $durationTooltip = 'Total request time: ' . Stopwatch::formatDuration($totals['duration_ms'])
            . ($isSlow
                ? '. Above the slow threshold of ' . $this->slowThresholdMs() . 'ms.'
                : '. Slow threshold: ' . $this->slowThresholdMs() . 'ms.');

        $html = '<span class="' . $durationClass . '" tabindex="0" data-sw-tip="' . e($durationTooltip) . '">' . StopwatchIcons::clock() . ' ' . e(Stopwatch::formatDuration($totals['duration_ms'])) . '</span>';

        $html .= $this->memoryPill($totals['memory_delta_bytes']);
        $html .= $this->countPill(StopwatchIcons::db(), $totals['queries_total'], $totals['query_ms_total'], 'q', 'database queries');

        return $html . $this->countPill(StopwatchIcons::globe(), $totals['http_total'], $totals['http_ms_total'], 'h', 'outgoing HTTP requests');
    }

    private function memoryPill(?int $bytes): string
    {
        if ($bytes === null) {
            return '';
        }

        $tooltip = 'Memory change from start to finish: ' . StopwatchCheckpoint::formatMemoryDelta($bytes) . '.';

        return '<span class="sw-pill" tabindex="0" data-sw-tip="' . e($tooltip) . '">' . StopwatchIcons::memory() . ' ' . e(StopwatchCheckpoint::formatMemoryDelta($bytes)) . '</span>';
    }

    private function countPill(string $glyph, ?int $count, ?float $ms, string $unit, string $noun): string
    {
        if ($count === null) {
            return '';
        }

        $label = $count . $unit;
        $tooltip = $count . ' ' . $noun;

        if ($ms !== null) {
            $label .= ' (' . Stopwatch::formatDuration($ms) . ')';
            $tooltip .= ', ' . Stopwatch::formatDuration($ms) . ' total';
        }

        return '<span class="sw-pill" tabindex="0" data-sw-tip="' . e($tooltip . '.') . '">' . $glyph . ' ' . e($label) . '</span>';
    }

    /** @param list<StopwatchCheckpoint> $checkpoints */
    private function panelTable(array $checkpoints, float $totalMs): string
    {
        if ($checkpoints === []) {
            return '<p class="sw-empty">No checkpoints recorded.</p>';
        }

        $rows = '';

        foreach ($checkpoints as $index => $checkpoint) {
            $delta = $checkpoint->timeSinceLastCheckpoint->totalMilliseconds;
            $cum = $checkpoint->timeSinceStopwatchStart->totalMilliseconds;
            $share = $totalMs > 0.0 ? ($delta / $totalMs) * 100.0 : 0.0;

            $rows .= '<tr>';
            $rows .= '<td>' . ($index + 1) . '</td>';
            $rows .= '<td>' . e($checkpoint->label) . '</td>';
            $rows .= '<td>' . e(Stopwatch::formatDuration($delta)) . '</td>';
            $rows .= '<td>' . e(Stopwatch::formatDuration($cum)) . '</td>';
            $rows .= '<td>' . e(number_format($share, 1)) . '%</td>';
            $rows .= '<td>' . e($this->intOrDash($checkpoint->queryCount)) . '</td>';
            $rows .= '<td>' . e($this->intOrDash($checkpoint->httpCount)) . '</td>';
            $rows .= '<td>' . e($this->memoryOrDash($checkpoint->memoryDelta)) . '</td>';
            $rows .= '</tr>';
        }

        return '<table class="sw-table">'
            . '<thead><tr>'
            . '<th>#</th><th>Label</th><th>&Delta;</th><th>Cumulative</th><th>Share</th>'
            . '<th>Queries</th><th>HTTP</th><th>Mem &Delta;</th>'
            . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table>';
    }

    private function intOrDash(?int $value): string
    {
        return $value === null ? '-' : (string) $value;
    }

    private function memoryOrDash(?int $bytes): string
    {
        return $bytes === null ? '-' : StopwatchCheckpoint::formatMemoryDelta($bytes);
    }

    private function css(): string
    {
        return <<<'CSS'
#stopwatch-toolbar{position:fixed;z-index:2147483647;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;color:#e8eaed;background:#202124;border:1px solid #3c4043;border-radius:6px;box-shadow:0 4px 18px rgba(0,0,0,.35);max-width:96vw;}
#stopwatch-toolbar.sw-pos-bottom-right{right:8px;bottom:8px;}
#stopwatch-toolbar.sw-pos-bottom-left{left:8px;bottom:8px;}
#stopwatch-toolbar.sw-pos-top-right{right:8px;top:8px;}
#stopwatch-toolbar.sw-pos-top-left{left:8px;top:8px;}
#stopwatch-toolbar .sw-summary{position:relative;z-index:1;cursor:pointer;list-style:none;padding:6px 10px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;}
#stopwatch-toolbar .sw-summary::-webkit-details-marker{display:none;}
#stopwatch-toolbar .sw-pill{padding:2px 8px;background:#303134;border-radius:999px;white-space:nowrap;cursor:help;}
#stopwatch-toolbar .sw-pill[data-sw-tip]::after{content:attr(data-sw-tip);position:absolute;opacity:0;visibility:hidden;transition:opacity .1s linear;white-space:normal;width:max-content;max-width:min(92vw,420px);padding:4px 8px;background:#3c4043;color:#e8eaed;border:1px solid #5f6368;border-radius:4px;font-size:11px;line-height:1.4;box-shadow:0 2px 8px rgba(0,0,0,.45);}
#stopwatch-toolbar .sw-pill[data-sw-tip]:hover::after,#stopwatch-toolbar .sw-pill[data-sw-tip]:focus-visible::after{opacity:1;visibility:visible;}
#stopwatch-toolbar .sw-pill:focus-visible{outline:1px solid #8ab4f8;outline-offset:1px;}
#stopwatch-toolbar.sw-pos-bottom-right .sw-pill::after,#stopwatch-toolbar.sw-pos-bottom-left .sw-pill::after{bottom:calc(100% + 6px);}
#stopwatch-toolbar.sw-pos-top-right .sw-pill::after,#stopwatch-toolbar.sw-pos-top-left .sw-pill::after{top:calc(100% + 6px);}
#stopwatch-toolbar.sw-pos-bottom-right .sw-pill::after,#stopwatch-toolbar.sw-pos-top-right .sw-pill::after{right:0;}
#stopwatch-toolbar.sw-pos-bottom-left .sw-pill::after,#stopwatch-toolbar.sw-pos-top-left .sw-pill::after{left:0;}
#stopwatch-toolbar .sw-pill-slow{background:#5a1d1d;color:#fbd6d6;}
#stopwatch-toolbar .sw-panel{padding:8px 10px;border-top:1px solid #3c4043;max-height:60vh;overflow:auto;}
#stopwatch-toolbar .sw-table{border-collapse:collapse;width:100%;}
#stopwatch-toolbar .sw-table th,#stopwatch-toolbar .sw-table td{padding:4px 8px;text-align:left;border-bottom:1px solid #3c4043;}
#stopwatch-toolbar .sw-table th{font-weight:600;color:#bdc1c6;}
#stopwatch-toolbar .sw-empty{margin:0;color:#bdc1c6;}
CSS;
    }
}
