<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

/**
 * Internal HTML renderer for a single stopwatch row. Output structure and
 * helper signatures are not part of the package's public API and may change
 * between minor releases without a deprecation cycle.
 *
 * @internal
 */
final class StopwatchCheckpointHtmlRenderer
{
    /** Memory deltas smaller than this in absolute bytes render in the dim color so noise stays quiet. */
    private const int MEMORY_DIM_THRESHOLD_BYTES = 10 * 1024;

    public static function row(StopwatchCheckpoint $checkpoint, float $totalMs, int $slowThreshold, string $color, int $index): string
    {
        $delta = $checkpoint->timeSinceLastCheckpoint->totalMilliseconds;
        $cum = (int) round($checkpoint->timeSinceStopwatchStart->totalMilliseconds);
        $share = $totalMs > 0 ? ($delta / $totalMs) * 100 : 0;
        $shareLabel = self::shareLabel($share);
        $barWidth = max(1.5, $share);
        $delayMs = $index * 60;

        $label = e($checkpoint->label);
        $deltaFmt = Stopwatch::formatDuration($delta);

        ['cls' => $slowClass, 'msColor' => $msColor, 'msWeight' => $msWeight, 'shareColor' => $shareColor, 'badge' => $slowBadge, 'stripe' => $slowStripe, 'hoverBg' => $slowHoverBg] = StopwatchSlowStyling::resolve($delta, $slowThreshold);

        $startMarker = $index === 0
            ? ' <span title="Time elapsed before the first checkpoint" style="font-size:9px;font-style:italic;color:var(--sw-text-muted,#94a3b8);flex-shrink:0;">since start</span>'
            : '';

        $metricStack = self::metricStack($checkpoint, $deltaFmt, $msColor, $msWeight, $slowClass !== '');
        $metaBlock = self::metadataBlock($checkpoint);
        $tip = self::tip($checkpoint, $deltaFmt, $cum, $shareLabel);
        $expansion = StopwatchExpansionRenderer::panel($checkpoint, $totalMs, $slowThreshold);

        return <<<HTML
            <div class="sw-row{$slowClass}" tabindex="0" role="button" aria-expanded="false" style="--sw-bar:{$color};--sw-stripe:{$slowStripe};--sw-slow-bg:{$slowHoverBg};position:relative;padding:11px 18px 12px;">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;">
                <div style="min-width:0;flex:1;display:flex;align-items:flex-start;gap:9px;">
                  <span class="sw-dot" style="width:9px;height:9px;border-radius:2.5px;background:{$color};flex-shrink:0;margin-top:5px;"></span>
                  <div style="min-width:0;flex:1;">
                    <div style="display:flex;align-items:center;gap:6px;line-height:1.3;">
                      <span style="font-weight:500;color:var(--sw-text,#0f172a);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;">{$label}</span>
                      {$slowBadge}{$startMarker}
                    </div>
                    {$metaBlock}
                  </div>
                </div>
                <div style="display:flex;align-items:flex-start;gap:8px;white-space:nowrap;flex-shrink:0;">
                  <div style="display:flex;align-items:center;gap:7px;margin-top:4px;">
                    <div style="width:72px;height:6px;border-radius:3px;background:var(--sw-border,#f1f5f9);overflow:hidden;">
                      <div class="sw-bar-fill" style="width:{$barWidth}%;height:100%;background:{$color};border-radius:3px;animation-delay:{$delayMs}ms;"></div>
                    </div>
                    <div style="font-size:10px;color:{$shareColor};font-variant-numeric:tabular-nums;min-width:28px;text-align:right;">{$shareLabel}</div>
                  </div>
                  {$metricStack}
                </div>
              </div>
              {$tip}
              {$expansion}
            </div>
            HTML;
    }

    public static function shareLabel(float $share): string
    {
        $shareInt = (int) round($share);

        return $share > 0 && $shareInt === 0 ? '&lt;1%' : $shareInt . '%';
    }

    public static function slowMarkColor(float $delta, int $slowThreshold): string
    {
        return StopwatchSlowStyling::markColor($delta, $slowThreshold);
    }

    private static function metricStack(StopwatchCheckpoint $checkpoint, string $deltaFmt, string $msColor, string $msWeight, bool $isSlow): string
    {
        $lines = [
            '<div style="font-weight:' . $msWeight . ';color:' . $msColor . ';font-variant-numeric:tabular-nums;font-size:14px;line-height:1.15;">' . $deltaFmt . '</div>',
        ];

        // Each renderer suppresses its own chip when the count is zero — keeps "tracking on but
        // nothing happened on this row" rows clean. Footer totals still show cumulative across all rows.
        $lines[] = StopwatchQueryRenderer::chip($checkpoint);
        $lines[] = StopwatchHttpRenderer::chip($checkpoint);
        $lines[] = self::memoryChip($checkpoint, $isSlow);

        return '<div style="text-align:right;min-width:54px;">' . implode('', $lines) . '</div>';
    }

    private static function memoryChip(StopwatchCheckpoint $checkpoint, bool $isSlow): string
    {
        if ($checkpoint->memoryDelta === null) {
            return '';
        }

        $color = self::memoryColor($checkpoint->memoryDelta, $isSlow);

        return '<div style="font-size:10px;color:' . $color . ';font-variant-numeric:tabular-nums;margin-top:3px;line-height:1.15;">'
            . e(StopwatchCheckpoint::formatMemoryDelta($checkpoint->memoryDelta))
            . '</div>';
    }

    private static function memoryColor(int $delta, bool $isSlow): string
    {
        // On slow rows the bg becomes pink; small grays disappear. Bump contrast.
        if ($isSlow) {
            return '#64748b';
        }

        return abs($delta) < self::MEMORY_DIM_THRESHOLD_BYTES
            ? 'var(--sw-mem-color-small,#cbd5e1)'
            : 'var(--sw-mem-color,#94a3b8)';
    }

    private static function metadataBlock(StopwatchCheckpoint $checkpoint): string
    {
        if ($checkpoint->metadata === null) {
            return '';
        }

        $chips = [];

        foreach ($checkpoint->metadata as $key => $value) {
            $chips[] = '<span style="display:inline-flex;align-items:baseline;gap:5px;padding:2px 7px;background:var(--sw-chip-bg,#f1f5f9);border-radius:4px;font-size:10.5px;line-height:1.6;">'
                . '<span style="color:var(--sw-chip-key,#94a3b8);font-weight:500;">'
                . e((string) $key)
                . '</span>'
                . '<span style="color:var(--sw-chip-val,#334155);font-variant-numeric:tabular-nums;">'
                . e(StopwatchCheckpoint::formatMetadataValue($value))
                . '</span>'
                . '</span>';
        }

        return '<div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:4px;">'
            . implode('', $chips)
            . '</div>';
    }

    private static function tip(StopwatchCheckpoint $checkpoint, string $deltaFmt, int $cum, string $shareLabel): string
    {
        $label = e($checkpoint->label);
        $time = e($checkpoint->time->format('H:i:s.v'));

        $cumFmt = Stopwatch::formatDuration($cum);

        $header = '<div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px;padding:9px 11px 7px;">'
            . '<span style="font-weight:600;color:var(--sw-tip-label,#fff);overflow-wrap:anywhere;">' . $label . '</span>'
            . '<span style="color:var(--sw-tip-mute,#64748b);font-size:10px;font-variant-numeric:tabular-nums;flex-shrink:0;white-space:nowrap;" title="Timestamp">' . $time . '</span>'
            . '</div>';

        $stats = '<div style="display:flex;border-top:1px solid var(--sw-tip-divider,rgba(255,255,255,.08));">'
            . self::statCell($deltaFmt, 'of ' . $cumFmt)
            . '<div style="width:1px;background:var(--sw-tip-divider,rgba(255,255,255,.08));"></div>'
            . self::statCell($shareLabel, 'of total')
            . '</div>';

        $detailLines = [];
        if ($checkpoint->queryCount !== null && $checkpoint->queryCount > 0) {
            $detailLines[] = self::tipQueryLine($checkpoint);
        }

        if ($checkpoint->httpCount !== null && $checkpoint->httpCount > 0) {
            $detailLines[] = StopwatchHttpRenderer::tipSection($checkpoint);
        }

        if ($checkpoint->memoryDelta !== null) {
            $detailLines[] = self::tipMemoryLine($checkpoint);
        }

        $details = $detailLines === []
            ? ''
            : '<div style="padding:7px 11px 9px;border-top:1px solid var(--sw-tip-divider,rgba(255,255,255,.08));display:flex;flex-direction:column;gap:3px;">' . implode('', $detailLines) . '</div>';

        return '<div class="sw-tip" style="display:none;padding:0;">' . $header . $stats . $details . '</div>';
    }

    private static function statCell(string $value, string $caption): string
    {
        return '<div style="flex:1;padding:7px 11px 8px;text-align:center;">'
            . '<div style="font-size:14px;font-weight:600;color:var(--sw-tip-label,#fff);font-variant-numeric:tabular-nums;line-height:1.1;">' . $value . '</div>'
            . '<div style="font-size:9px;color:var(--sw-tip-mute,#64748b);text-transform:uppercase;letter-spacing:.06em;margin-top:3px;">' . $caption . '</div>'
            . '</div>';
    }

    private static function tipQueryLine(StopwatchCheckpoint $checkpoint): string
    {
        $word = $checkpoint->queryCount === 1 ? 'query' : 'queries';
        $time = Stopwatch::formatDuration($checkpoint->queryTimeMs ?? 0);

        return self::tipLine(
            StopwatchIcons::db(),
            $checkpoint->queryCount . ' ' . $word . ' <span style="color:var(--sw-tip-mute,#64748b);">in</span> ' . $time,
        );
    }

    private static function tipMemoryLine(StopwatchCheckpoint $checkpoint): string
    {
        $delta = e(StopwatchCheckpoint::formatMemoryDelta($checkpoint->memoryDelta ?? 0));
        $current = e(StopwatchCheckpoint::formatBytes($checkpoint->memoryUsage ?? 0));
        $peak = e(StopwatchCheckpoint::formatBytes($checkpoint->memoryPeak ?? 0));

        return self::tipLine(
            StopwatchIcons::memory(),
            $delta . ' <span style="color:var(--sw-tip-mute,#64748b);">· now ' . $current . ' · peak ' . $peak . '</span>',
        );
    }

    public static function tipLine(string $icon, string $value): string
    {
        return '<div style="display:flex;align-items:center;gap:7px;line-height:1.5;">'
            . '<span style="color:var(--sw-tip-mute,#64748b);display:inline-flex;">' . $icon . '</span>'
            . '<span style="color:var(--sw-tip-label,#fff);font-variant-numeric:tabular-nums;">' . $value . '</span>'
            . '</div>';
    }
}
