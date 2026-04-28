<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * @extends Collection<array-key, StopwatchCheckpoint>
 * @property StopwatchCheckpoint[] $items
 * @method self add(StopwatchCheckpoint $item)
 */
final class StopwatchCheckpointCollection extends Collection
{
    /**
     * 12 muted, evenly-spaced hues. No red/orange — those are reserved for the "slow" signal.
     */
    private const array PALETTE = [
        '#6e9bc4', '#67a98f', '#c9a25a', '#9889b8',
        '#6cabb5', '#c08fa3', '#bba767', '#92b475',
        '#6fb09f', '#8b91c4', '#b58bbe', '#7aa9c4',
    ];

    /**
     * @param array<array-key, mixed>|null $metadata
     * @param list<array{method: string, url: string, status: int, durationMs: float}>|null $httpCalls
     * @param list<array{sql: string, bindings: array<array-key, mixed>, durationMs: float}>|null $queryCalls
     */
    public function addCheckpoint(
        string          $label,
        ?array          $metadata,
        float           $timeSinceLastCheckpointMs,
        float           $timeSinceStopwatchStartMs,
        CarbonImmutable $time,
        ?int            $queryCount = null,
        ?float          $queryTimeMs = null,
        ?int            $memoryUsage = null,
        ?int            $memoryDelta = null,
        ?int            $memoryPeak = null,
        ?int            $httpCount = null,
        ?float          $httpTimeMs = null,
        ?array          $httpCalls = null,
        ?array          $queryCalls = null,
    ): self {
        return $this->add(
            new StopwatchCheckpoint(
                label: $label,
                metadata: $metadata,
                timeSinceLastCheckpointMs: $timeSinceLastCheckpointMs,
                timeSinceStopwatchStartMs: $timeSinceStopwatchStartMs,
                time: $time,
                queryCount: $queryCount,
                queryTimeMs: $queryTimeMs,
                memoryUsage: $memoryUsage,
                memoryDelta: $memoryDelta,
                memoryPeak: $memoryPeak,
                httpCount: $httpCount,
                httpTimeMs: $httpTimeMs,
                httpCalls: $httpCalls,
                queryCalls: $queryCalls,
            ),
        );
    }

    /**
     * @internal Renders the checkpoint rows for the HTML profile. Output structure is not stable.
     */
    public function render(float $totalMs, int $slowThreshold): string
    {
        $out = '';
        $idx = 0;

        foreach ($this->items as $item) {
            $color = self::PALETTE[$idx % count(self::PALETTE)];
            $out .= StopwatchCheckpointHtmlRenderer::row($item, $totalMs, $slowThreshold, $color, $idx);
            $idx++;
        }

        return $out;
    }

    /**
     * @internal Renders the overview-bar segments for the HTML profile. Output structure is not stable.
     */
    public function renderSegments(float $totalMs, int $slowThreshold): string
    {
        // Segments and their tooltips render as siblings inside the overview bar (not nested),
        // so that any `transform` on a hovered segment does not also visually scale its tip.
        $segs = '';
        $tips = '';

        foreach ($this->items as $idx => $item) {
            $delta = $item->timeSinceLastCheckpoint->totalMilliseconds;
            $share = $totalMs > 0 ? ($delta / $totalMs) * 100 : 0;
            $shareLabel = StopwatchCheckpointHtmlRenderer::shareLabel($share);
            $color = self::PALETTE[$idx % count(self::PALETTE)];
            $label = e($item->label);
            $deltaFmt = Stopwatch::formatDuration($delta);
            $isSlow = $delta >= $slowThreshold;
            $slowPill = $isSlow
                ? '<span style="display:inline-block;font-size:9px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#dc2626;background:transparent;border:1px solid #dc2626;padding:1px 6px;border-radius:999px;vertical-align:1px;margin:0 6px 0 4px;">slow</span>'
                : '';

            $ariaLabel = $label . ', ' . $deltaFmt . ', ' . $shareLabel . ' of total'
                . ($isSlow ? ', slow' : '');

            $segs .= '<div class="sw-seg" tabindex="0" aria-label="' . $ariaLabel . '" data-sw-tip="' . $idx . '" style="width:' . $share . '%;background:' . $color . ';"></div>';
            $tips .= '<div class="sw-tip sw-seg-tip" data-sw-tip="' . $idx . '" style="display:none;">'
                . '<div style="display:flex;align-items:baseline;gap:6px;flex-wrap:wrap;margin-bottom:3px;">'
                    . '<span style="font-weight:600;color:var(--sw-tip-label,#fff);overflow-wrap:anywhere;">' . $label . '</span>'
                    . $slowPill
                . '</div>'
                . '<div style="white-space:nowrap;">'
                    . '<span style="font-variant-numeric:tabular-nums;color:var(--sw-tip-label,#fff);font-weight:500;">' . $deltaFmt . '</span>'
                    . '<span style="color:var(--sw-tip-mute,#64748b);margin:0 6px;">·</span>'
                    . '<span style="font-variant-numeric:tabular-nums;color:var(--sw-tip-label,#fff);font-weight:500;">' . $shareLabel . '</span>'
                    . '<span style="color:var(--sw-tip-mute,#64748b);margin-left:4px;">of total</span>'
                . '</div>'
                . '</div>';
        }

        return $segs . $tips;
    }

    public function lastCheckpoint(): ?StopwatchCheckpoint
    {
        return $this->last();
    }

    /**
     * @return array{queries: int, queryMs: float, memoryDelta: int, httpCount: int, httpMs: float, hasQueries: bool, hasMemory: bool, hasHttp: bool}
     */
    public function totals(): array
    {
        $queries = 0;
        $queryMs = 0.0;
        $memoryDelta = 0;
        $httpCount = 0;
        $httpMs = 0.0;
        $hasQueries = false;
        $hasMemory = false;
        $hasHttp = false;

        foreach ($this->items as $item) {
            if ($item->queryCount !== null) {
                $hasQueries = true;
                $queries += $item->queryCount;
                $queryMs += $item->queryTimeMs ?? 0.0;
            }

            if ($item->memoryDelta !== null) {
                $hasMemory = true;
                $memoryDelta += $item->memoryDelta;
            }

            if ($item->httpCount !== null) {
                $hasHttp = true;
                $httpCount += $item->httpCount;
                $httpMs += $item->httpTimeMs ?? 0.0;
            }
        }

        return [
            'queries' => $queries,
            'queryMs' => $queryMs,
            'memoryDelta' => $memoryDelta,
            'httpCount' => $httpCount,
            'httpMs' => $httpMs,
            'hasQueries' => $hasQueries,
            'hasMemory' => $hasMemory,
            'hasHttp' => $hasHttp,
        ];
    }
}
