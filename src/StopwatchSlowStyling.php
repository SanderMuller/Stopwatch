<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

/**
 * Internal helpers for the per-row "slow" signal — class + colors + badge HTML.
 * Extracted from StopwatchCheckpointHtmlRenderer to keep per-class cognitive
 * complexity within the project's PHPStan budget.
 *
 * @internal
 */
final class StopwatchSlowStyling
{
    /**
     * @return array{cls: string, msColor: string, msWeight: string, shareColor: string, badge: string, stripe: string, hoverBg: string}
     */
    public static function resolve(float $delta, int $slowThreshold): array
    {
        if ($delta < $slowThreshold) {
            return ['cls' => '', 'msColor' => 'var(--sw-text,#0f172a)', 'msWeight' => '600', 'shareColor' => 'var(--sw-text-muted,#94a3b8)', 'badge' => '', 'stripe' => '', 'hoverBg' => ''];
        }

        $palette = self::palette($delta / max(1, $slowThreshold));

        return [
            'cls' => ' sw-slow',
            'msColor' => $palette['ms'],
            'msWeight' => '700',
            'shareColor' => $palette['ms'],
            'badge' => ' <span aria-label="slower than threshold" style="font-size:9px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:' . $palette['pill'] . ';background:transparent;border:1px solid ' . $palette['pill'] . ';padding:1px 6px;border-radius:999px;vertical-align:1px;flex-shrink:0;">slow</span>',
            'stripe' => $palette['stripe'],
            'hoverBg' => $palette['hoverBg'],
        ];
    }

    public static function markColor(float $delta, int $slowThreshold): string
    {
        return self::palette($delta / max(1, $slowThreshold))['stripe'];
    }

    /**
     * Slow severity intensifies the red tone with how many multiples of the threshold the
     * checkpoint took. Just-over-threshold reads softly; way-over reads punchy.
     *
     * @return array{ms: string, pill: string, stripe: string, hoverBg: string}
     */
    private static function palette(float $factor): array
    {
        // light: 1×–2×, medium: 2×–5×, heavy: 5×+
        if ($factor >= 5) {
            return ['ms' => '#b91c1c', 'pill' => '#dc2626', 'stripe' => '#dc2626', 'hoverBg' => '#fecaca'];
        }

        if ($factor >= 2) {
            return ['ms' => '#dc2626', 'pill' => '#ef4444', 'stripe' => '#ef4444', 'hoverBg' => '#fee2e2'];
        }

        return ['ms' => '#ef4444', 'pill' => '#f87171', 'stripe' => '#fca5a5', 'hoverBg' => '#fef2f2'];
    }
}
