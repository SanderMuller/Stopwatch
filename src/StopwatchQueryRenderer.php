<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

/**
 * Internal HTML helpers for the query-tracking chip + expansion section. Mirrors
 * StopwatchHttpRenderer; both extracted from StopwatchCheckpointHtmlRenderer +
 * StopwatchExpansionRenderer to keep per-class cognitive complexity within budget.
 *
 * @internal
 */
final class StopwatchQueryRenderer
{
    public static function chip(StopwatchCheckpoint $checkpoint): string
    {
        if ($checkpoint->queryCount === null || $checkpoint->queryCount === 0) {
            return '';
        }

        $time = Stopwatch::formatDuration($checkpoint->queryTimeMs ?? 0);
        $icon = StopwatchIcons::db('width:10px;height:10px;display:inline-block;flex-shrink:0;');

        return '<div style="font-size:10px;color:var(--sw-query-color,#7c3aed);font-variant-numeric:tabular-nums;margin-top:3px;line-height:1.15;display:flex;align-items:center;gap:4px;justify-content:flex-end;">'
            . $icon . $checkpoint->queryCount . ' · ' . $time
            . '</div>';
    }

    public static function expandedSection(StopwatchCheckpoint $checkpoint): string
    {
        if ($checkpoint->queryCount === null || $checkpoint->queryCount === 0) {
            return '';
        }

        $word = $checkpoint->queryCount === 1 ? 'query' : 'queries';
        $heading = StopwatchExpansionRenderer::sectionHeading(
            $checkpoint->queryCount . ' ' . $word . ' · ' . Stopwatch::formatDuration($checkpoint->queryTimeMs ?? 0),
        );

        $calls = $checkpoint->queryCalls ?? [];
        if ($calls === []) {
            return $heading . '<div style="color:var(--sw-text-muted,#94a3b8);font-size:10.5px;margin-bottom:9px;">SQL detail not captured.</div>';
        }

        $rows = '';
        foreach ($calls as $call) {
            $rows .= self::queryRow($call);
        }

        $remaining = $checkpoint->queryCount - count($calls);
        if ($remaining > 0) {
            $rows .= '<div style="font-size:10.5px;color:var(--sw-text-muted,#94a3b8);margin-top:2px;">+' . $remaining . ' more (capped)</div>';
        }

        return $heading . '<div style="display:flex;flex-direction:column;gap:5px;margin-bottom:9px;">' . $rows . '</div>';
    }

    /**
     * @param array{sql: string, bindings: array<array-key, mixed>, durationMs: float} $call
     */
    private static function queryRow(array $call): string
    {
        $bindings = $call['bindings'] === []
            ? ''
            : '<span style="color:var(--sw-text-muted,#94a3b8);font-size:10.5px;margin-left:6px;">[' . e(self::formatBindings($call['bindings'])) . ']</span>';

        return '<div>'
            . '<div style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11px;color:var(--sw-query-color,#7c3aed);overflow-wrap:anywhere;">' . e($call['sql']) . '</div>'
            . '<div style="font-size:10.5px;color:var(--sw-text-muted,#94a3b8);font-variant-numeric:tabular-nums;">'
            . Stopwatch::formatDuration($call['durationMs']) . $bindings
            . '</div>'
            . '</div>';
    }

    /**
     * @param array<array-key, mixed> $bindings
     */
    private static function formatBindings(array $bindings): string
    {
        $parts = [];
        foreach ($bindings as $binding) {
            $parts[] = StopwatchCheckpoint::formatMetadataValue($binding);
        }

        return implode(', ', $parts);
    }
}
