<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

/**
 * Internal HTML renderer for the click-to-expand panel that appears under each row.
 * Hidden by default with inline `display:none`; the renderer's INLINE_SCRIPT toggles
 * a `.sw-expanded` class on the parent row to reveal it. Output structure not stable.
 *
 * @internal
 */
final class StopwatchExpansionRenderer
{
    public static function panel(StopwatchCheckpoint $checkpoint, float $totalMs, int $slowThreshold): string
    {
        $sections = [
            self::headerSection($checkpoint, $totalMs, $slowThreshold),
            self::metadataSection($checkpoint),
            self::memorySection($checkpoint),
            StopwatchQueryRenderer::expandedSection($checkpoint),
            StopwatchHttpRenderer::expandedSection($checkpoint),
        ];

        $body = implode('', array_filter($sections, static fn (string $s): bool => $s !== ''));

        // Outer wrapper is the backdrop (CSS makes it position:fixed overlay when row is .sw-expanded);
        // inner card is the modal itself. Both hidden via inline display:none for mail safety.
        return '<div class="sw-expansion" style="display:none;">'
            . '<div class="sw-modal-card" style="background:var(--sw-bg,#fff);border:1px solid var(--sw-border,#f1f5f9);border-radius:14px;box-shadow:0 16px 48px -8px rgba(15,23,42,.35);padding:28px 30px;font-size:11.5px;line-height:1.5;color:var(--sw-text,#0f172a);">'
                . self::closeButton()
                . $body
            . '</div>'
            . '</div>';
    }

    private static function closeButton(): string
    {
        return '<button type="button" class="sw-modal-close" aria-label="Close" style="float:right;background:transparent;border:0;cursor:pointer;color:var(--sw-text-muted,#94a3b8);font-size:20px;padding:0;line-height:1;margin:-8px -10px 0 8px;">×</button>';
    }

    public static function sectionHeading(string $text): string
    {
        // Edge-to-edge top divider via negative margin matching the modal-card's 30px horizontal padding.
        // Divider sits above the heading so each section visually breaks from the previous content.
        return '<div class="sw-section-heading" style="font-size:13.5px;font-weight:700;color:var(--sw-text,#0f172a);margin:22px -30px 12px;padding:14px 30px 0;border-top:1px solid var(--sw-border,#f1f5f9);">' . e($text) . '</div>';
    }

    private static function headerSection(StopwatchCheckpoint $checkpoint, float $totalMs, int $slowThreshold): string
    {
        $label = e($checkpoint->label);
        $time = e($checkpoint->time->format('H:i:s.v'));
        $delta = $checkpoint->timeSinceLastCheckpoint->totalMilliseconds;
        $cum = $checkpoint->timeSinceStopwatchStart->totalMilliseconds;
        $share = $totalMs > 0 ? ($delta / $totalMs) * 100 : 0;
        $shareLabel = StopwatchCheckpointHtmlRenderer::shareLabel($share);
        $slowFactor = $delta >= $slowThreshold && $slowThreshold > 0
            ? ' · <span style="color:#dc2626;font-weight:600;">' . round($delta / $slowThreshold, 1) . '× slow threshold</span>'
            : '';

        return '<div style="font-weight:700;font-size:18px;color:var(--sw-text,#0f172a);margin-bottom:6px;overflow-wrap:anywhere;">' . $label . '</div>'
            . '<div style="font-size:11px;color:var(--sw-text-muted,#94a3b8);font-variant-numeric:tabular-nums;">'
            . $time . ' · '
            . Stopwatch::formatDuration($delta) . ' (' . $shareLabel . ' of total) · cumulative ' . Stopwatch::formatDuration($cum)
            . $slowFactor
            . '</div>';
    }

    private static function metadataSection(StopwatchCheckpoint $checkpoint): string
    {
        if ($checkpoint->metadata === null || $checkpoint->metadata === []) {
            return '';
        }

        $rows = '';
        foreach ($checkpoint->metadata as $key => $value) {
            $rows .= '<div style="display:flex;gap:8px;font-variant-numeric:tabular-nums;">'
                . '<span style="color:var(--sw-text-muted,#94a3b8);min-width:80px;flex-shrink:0;">' . e((string) $key) . '</span>'
                . '<span style="color:var(--sw-text,#0f172a);overflow-wrap:anywhere;min-width:0;">' . e(StopwatchCheckpoint::formatMetadataValue($value)) . '</span>'
                . '</div>';
        }

        return self::sectionHeading('Metadata') . '<div style="display:flex;flex-direction:column;gap:2px;margin-bottom:9px;">' . $rows . '</div>';
    }

    private static function memorySection(StopwatchCheckpoint $checkpoint): string
    {
        if ($checkpoint->memoryDelta === null) {
            return '';
        }

        return self::sectionHeading('Memory')
            . '<div style="font-variant-numeric:tabular-nums;font-size:12px;color:var(--sw-text-muted,#94a3b8);">'
            . 'now <strong style="font-weight:700;color:var(--sw-text,#0f172a);font-size:13px;">' . e(StopwatchCheckpoint::formatBytes($checkpoint->memoryUsage ?? 0)) . '</strong>'
            . ' · Δ <strong style="font-weight:700;color:var(--sw-text,#0f172a);font-size:13px;">' . e(StopwatchCheckpoint::formatMemoryDelta($checkpoint->memoryDelta)) . '</strong>'
            . ' · peak <strong style="font-weight:700;color:var(--sw-text,#0f172a);font-size:13px;">' . e(StopwatchCheckpoint::formatBytes($checkpoint->memoryPeak ?? 0)) . '</strong>'
            . '</div>';
    }
}
