<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

/**
 * Internal HTML helpers for the HTTP-tracking tooltip section. Extracted from
 * StopwatchCheckpointHtmlRenderer to keep per-class complexity within the
 * project's PHPStan budget. Output structure is not stable.
 *
 * @internal
 */
final class StopwatchHttpRenderer
{
    /** Number of HTTP call detail rows to inline in the tooltip preview. Beyond this, an "+N more" row appears. */
    private const int TIP_PREVIEW_LIMIT = 3;

    public static function chip(StopwatchCheckpoint $checkpoint): string
    {
        if ($checkpoint->httpCount === null || $checkpoint->httpCount === 0) {
            return '';
        }

        $time = Stopwatch::formatDuration($checkpoint->httpTimeMs ?? 0);
        $icon = StopwatchIcons::globe('width:10px;height:10px;display:inline-block;flex-shrink:0;');

        return '<div style="font-size:10px;color:var(--sw-http-color,#0ea5e9);font-variant-numeric:tabular-nums;margin-top:3px;line-height:1.15;display:flex;align-items:center;gap:4px;justify-content:flex-end;">'
            . $icon . $checkpoint->httpCount . ' · ' . $time
            . '</div>';
    }

    public static function expandedSection(StopwatchCheckpoint $checkpoint): string
    {
        if ($checkpoint->httpCount === null || $checkpoint->httpCount === 0) {
            return '';
        }

        $word = $checkpoint->httpCount === 1 ? 'call' : 'calls';
        $heading = StopwatchExpansionRenderer::sectionHeading(
            $checkpoint->httpCount . ' HTTP ' . $word . ' · ' . Stopwatch::formatDuration($checkpoint->httpTimeMs ?? 0),
        );

        $calls = $checkpoint->httpCalls ?? [];
        $rows = '';
        foreach ($calls as $call) {
            $rows .= self::expandedRow($call);
        }

        $remaining = $checkpoint->httpCount - count($calls);
        if ($remaining > 0) {
            $rows .= '<div style="font-size:10.5px;color:var(--sw-text-muted,#94a3b8);margin-top:2px;">+' . $remaining . ' more (capped)</div>';
        }

        return $heading . '<div style="display:flex;flex-direction:column;gap:3px;">' . $rows . '</div>';
    }

    /**
     * @param array{method: string, url: string, status: int, durationMs: float} $call
     */
    private static function expandedRow(array $call): string
    {
        $statusColor = self::statusColor($call['status']);
        $statusLabel = $call['status'] === 0 ? 'ERR' : (string) $call['status'];

        return '<div style="font-variant-numeric:tabular-nums;display:flex;gap:6px;align-items:baseline;font-size:11px;">'
            . '<span style="font-weight:600;color:var(--sw-text-muted,#94a3b8);min-width:42px;flex-shrink:0;">' . e($call['method']) . '</span>'
            . '<span style="color:var(--sw-text,#0f172a);overflow-wrap:anywhere;min-width:0;flex:1;">' . e($call['url']) . '</span>'
            . '<span style="font-weight:600;color:' . $statusColor . ';flex-shrink:0;">' . $statusLabel . '</span>'
            . '<span style="color:var(--sw-text-muted,#94a3b8);flex-shrink:0;">' . Stopwatch::formatDuration($call['durationMs']) . '</span>'
            . '</div>';
    }

    public static function tipSection(StopwatchCheckpoint $checkpoint): string
    {
        $word = $checkpoint->httpCount === 1 ? 'call' : 'calls';
        $time = Stopwatch::formatDuration($checkpoint->httpTimeMs ?? 0);

        $summary = StopwatchCheckpointHtmlRenderer::tipLine(
            StopwatchIcons::globe(),
            $checkpoint->httpCount . ' ' . $word . ' <span style="color:var(--sw-tip-mute,#64748b);">in</span> ' . $time,
        );

        $calls = $checkpoint->httpCalls ?? [];
        if ($calls === []) {
            return $summary;
        }

        return $summary . self::callList($calls, $checkpoint->httpCount ?? 0);
    }

    /**
     * @param list<array{method: string, url: string, status: int, durationMs: float}> $calls
     */
    private static function callList(array $calls, int $totalCalls): string
    {
        $previewCount = min(self::TIP_PREVIEW_LIMIT, count($calls));
        $rows = '';
        for ($i = 0; $i < $previewCount; $i++) {
            $rows .= self::callRow($calls[$i]);
        }

        $remaining = $totalCalls - $previewCount;
        if ($remaining > 0) {
            $rows .= '<div style="font-size:10px;color:var(--sw-tip-mute,#64748b);padding-left:21px;margin-top:1px;">+' . $remaining . ' more</div>';
        }

        return '<div style="display:flex;flex-direction:column;gap:2px;margin-top:2px;">' . $rows . '</div>';
    }

    /**
     * URLs in `httpCalls` are already stripped of query strings at capture time
     * (`Stopwatch::stripUrlQueryString`), so no defensive strip here.
     *
     * @param array{method: string, url: string, status: int, durationMs: float} $call
     */
    private static function callRow(array $call): string
    {
        $statusColor = self::statusColor($call['status']);
        $statusLabel = $call['status'] === 0 ? 'ERR' : (string) $call['status'];
        $duration = Stopwatch::formatDuration($call['durationMs']);

        return '<div style="font-size:10.5px;font-variant-numeric:tabular-nums;display:flex;gap:6px;align-items:baseline;padding-left:21px;line-height:1.4;">'
            . '<span style="font-weight:600;color:var(--sw-tip-mute,#94a3b8);min-width:32px;flex-shrink:0;">' . e($call['method']) . '</span>'
            . '<span style="color:var(--sw-tip-label,#fff);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;flex:1;">' . e($call['url']) . '</span>'
            . '<span style="font-weight:600;color:' . $statusColor . ';flex-shrink:0;">' . $statusLabel . '</span>'
            . '<span style="color:var(--sw-tip-mute,#64748b);flex-shrink:0;">' . $duration . '</span>'
            . '</div>';
    }

    private static function statusColor(int $status): string
    {
        return match (true) {
            $status === 0, $status >= 500 => '#ef4444',
            $status >= 400 => '#f59e0b',
            $status >= 200 && $status < 300 => '#22c55e',
            default => 'var(--sw-tip-mute,#94a3b8)',
        };
    }
}
