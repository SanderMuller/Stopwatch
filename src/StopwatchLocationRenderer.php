<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

/**
 * @internal Renders a checkpoint's `path:line` under its label in the HTML report (debug mode only).
 */
final class StopwatchLocationRenderer
{
    public static function line(?string $location): string
    {
        if ($location === null) {
            return '';
        }

        return '<div class="sw-location" style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:10.5px;color:var(--sw-text-muted,#94a3b8);overflow-wrap:anywhere;margin-top:2px;">' . e($location) . '</div>';
    }
}
