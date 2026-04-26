<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

/**
 * Internal HTML renderer for the full stopwatch card. Output structure, class
 * names, and embedded CSS/JS are not part of the package's public API and may
 * change between minor releases without a deprecation cycle.
 *
 * @internal
 */
final class StopwatchHtmlRenderer
{
    /**
     * Inline JS attached to each rendered card. Defines a one-time global initializer
     * (`window.__swInit`) that wires hover cross-highlighting, the theme toggle, and
     * localStorage persistence; subsequent cards reuse the same closure. Idempotent
     * via `card.__swDone` so re-rendering or duplicate scripts do nothing.
     */
    private const string INLINE_SCRIPT = <<<'JS'
        (function(){
            var s = document.currentScript;
            var c = s ? s.closest('.sw-stopwatch') : null;
            if (!c) return;
            if (!window.__swInit) {
                // Re-position a segment's tooltip from live measurements: clamp the body inside
                // the bar and slide the arrow to the segment center inside the tooltip body.
                // PHP renders an estimated fallback inline; this overrides on real interaction.
                window.__swSegTip = function(seg){
                    var bar = seg.parentElement;
                    if (!bar) return null;
                    var idx = seg.getAttribute('data-sw-tip');
                    if (idx === null) return null;
                    return bar.querySelector('.sw-seg-tip[data-sw-tip="' + idx + '"]');
                };
                window.__swPositionSegTip = function(seg){
                    var bar = seg.parentElement;
                    var tip = window.__swSegTip(seg);
                    if (!tip || !bar) return;
                    var origDisplay = tip.style.display;
                    var origVisibility = tip.style.visibility;
                    if (origDisplay === 'none') {
                        tip.style.visibility = 'hidden';
                        tip.style.display = 'block';
                    }
                    var barRect = bar.getBoundingClientRect();
                    var segRect = seg.getBoundingClientRect();
                    var tipWidth = tip.offsetWidth;
                    if (origDisplay === 'none') {
                        tip.style.display = origDisplay;
                        tip.style.visibility = origVisibility;
                    }
                    if (!barRect.width || !tipWidth) return;
                    var segCenter = segRect.left + segRect.width / 2;
                    var tipLeft = segCenter - tipWidth / 2;
                    if (tipLeft < barRect.left) tipLeft = barRect.left;
                    if (tipLeft > barRect.right - tipWidth) tipLeft = barRect.right - tipWidth;
                    // Tip is a sibling of segments inside the bar; its containing block is the bar.
                    tip.style.left = (tipLeft - barRect.left) + 'px';
                    tip.style.right = 'auto';
                    var arrow = segCenter - tipLeft;
                    if (arrow < 10) arrow = 10;
                    if (arrow > tipWidth - 10) arrow = tipWidth - 10;
                    tip.style.setProperty('--sw-arrow-left', arrow + 'px');
                };
                window.__swShowSegTip = function(seg){
                    var tip = window.__swSegTip(seg);
                    if (!tip) return;
                    window.__swPositionSegTip(seg);
                    tip.classList.add('sw-show');
                };
                window.__swHideSegTip = function(seg){
                    var tip = window.__swSegTip(seg);
                    if (tip) tip.classList.remove('sw-show');
                };
                window.__swInit = function(card){
                    if (card.__swDone) return;
                    card.__swDone = true;
                    card.classList.add('sw-js');
                    var rows = card.querySelectorAll('.sw-row');
                    var segs = card.querySelectorAll('.sw-seg');
                    // Pre-position every segment tooltip from live measurements so the very first
                    // hover already shows the arrow on the segment center.
                    for (var k = 0; k < segs.length; k++) window.__swPositionSegTip(segs[k]);
                    for (var i = 0; i < rows.length; i++) {
                        (function(r, sg){
                            if (!sg) return;
                            r.addEventListener('mouseenter', function(){ sg.classList.add('sw-active'); window.__swShowSegTip(sg); });
                            r.addEventListener('mouseleave', function(){ sg.classList.remove('sw-active'); window.__swHideSegTip(sg); });
                            sg.addEventListener('mouseenter', function(){ r.classList.add('sw-active'); window.__swShowSegTip(sg); });
                            sg.addEventListener('mouseleave', function(){ r.classList.remove('sw-active'); window.__swHideSegTip(sg); });
                            sg.addEventListener('focusin', function(){ window.__swShowSegTip(sg); });
                            sg.addEventListener('focusout', function(){ window.__swHideSegTip(sg); });
                        })(rows[i], segs[i]);
                    }
                    var copyBtn = card.querySelector('.sw-copy');
                    if (copyBtn) {
                        copyBtn.style.display = '';
                        copyBtn.addEventListener('click', function(){
                            var b64 = card.getAttribute('data-sw-md') || '';
                            var md = '';
                            try {
                                // Decode through TextDecoder so multi-byte UTF-8 (e.g. the slow-tier "×")
                                // round-trips correctly — atob alone yields a per-byte "binary string".
                                var bin = atob(b64);
                                var bytes = new Uint8Array(bin.length);
                                for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
                                md = new TextDecoder().decode(bytes);
                            } catch (e) {}
                            if (!md || !navigator.clipboard) return;
                            navigator.clipboard.writeText(md).then(function(){
                                copyBtn.classList.add('sw-copied');
                                setTimeout(function(){ copyBtn.classList.remove('sw-copied'); }, 1500);
                            }).catch(function(){});
                        });
                    }
                    var b = card.querySelector('.sw-theme-toggle');
                    if (b) {
                        b.style.display = '';
                        try {
                            var v = localStorage.getItem('sw-theme');
                            if (v === 'light' || v === 'dark') card.setAttribute('data-theme', v);
                        } catch (e) {}
                        b.addEventListener('click', function(){
                            var cur = card.getAttribute('data-theme');
                            if (!cur) {
                                cur = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                            }
                            var n = cur === 'dark' ? 'light' : 'dark';
                            // Only update this card — preserve any explicit `data-theme` overrides
                            // on other cards (e.g. side-by-side light+dark comparison pages).
                            card.setAttribute('data-theme', n);
                            try { localStorage.setItem('sw-theme', n); } catch (e) {}
                        });
                    }
                };
            }
            window.__swInit(c);
        })();
        JS;

    /** Dark-mode CSS variable values, applied for both system preference and `data-theme="dark"`. */
    private const string DARK_VARS = '--sw-bg: #141d33; --sw-text: #e2e8f0; --sw-text-muted: #94a3b8;'
        . ' --sw-text-dim: #64748b; --sw-border: #1e293b; --sw-chip-bg: #1e293b;'
        . ' --sw-chip-key: #94a3b8; --sw-chip-val: #e2e8f0; --sw-hover-bg: #283556;'
        . ' --sw-tip-bg: #f8fafc; --sw-tip-text: #475569; --sw-tip-label: #0f172a;'
        . ' --sw-tip-mute: #64748b; --sw-tip-divider: rgba(15,23,42,.08);'
        . ' --sw-mem-color: #64748b; --sw-mem-color-small: #475569;'
        . ' --sw-query-color: #c4b5fd; --sw-active-ring: #141d33;';

    private const string DARK_FILTER = 'filter: saturate(1.3) brightness(1.12);';

    public static function render(
        string $startLabel,
        string $endLabel,
        float $totalMs,
        string $totalLabel,
        StopwatchCheckpointCollection $checkpoints,
        int $slowThresholdMs,
        string $tail,
        string $markdown = '',
    ): string {
        $markdownB64 = base64_encode($markdown);
        $iconCopy = StopwatchIcons::clipboard('width:14px;height:14px;display:inline-block;flex-shrink:0;');
        $iconCheck = StopwatchIcons::check('width:14px;height:14px;display:inline-block;flex-shrink:0;');
        $count = $checkpoints->count();
        $segments = $checkpoints->renderSegments($totalMs, $slowThresholdMs);
        $rows = $checkpoints->render($totalMs, $slowThresholdMs);
        $totalsLabel = self::renderTotals($checkpoints->totals());

        $tailLabel = '<span title="Time elapsed between the last checkpoint and when the stopwatch finished" style="display:inline-flex;align-items:center;gap:4px;cursor:help;">' . StopwatchIcons::clock() . $tail . '</span>';
        $totalIcon = StopwatchIcons::clock('width:14px;height:14px;display:inline-block;flex-shrink:0;');
        $iconSun = StopwatchIcons::sun('width:14px;height:14px;display:inline-block;flex-shrink:0;');
        $iconMoon = StopwatchIcons::moon('width:14px;height:14px;display:inline-block;flex-shrink:0;');

        $body = $count === 0
            ? '<div style="padding:32px 18px;text-align:center;color:var(--sw-text-muted,#94a3b8);font-size:12px;">No checkpoints recorded</div>'
            : '<div style="border-top:1px solid var(--sw-border,#f1f5f9);">' . $rows . '</div>';

        $styleBlock = self::styleBlock();
        $inlineScript = self::INLINE_SCRIPT;

        return <<<HTML
        <div class="sw-stopwatch" role="region" aria-label="Stopwatch profile" data-sw-md="{$markdownB64}" style="max-width:460px;width:100%;background:var(--sw-bg,#fff);border:1px solid var(--sw-border,#f1f5f9);border-radius:12px;box-shadow:0 1px 2px rgba(15,23,42,.04),0 8px 24px -8px rgba(15,23,42,.12);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:var(--sw-text,#0f172a);font-size:14px;line-height:1.4;margin:15px 5px;">
            {$styleBlock}
            <header style="padding:16px 18px 14px;position:sticky;top:0;background:var(--sw-bg,#fff);z-index:5;border-radius:12px 12px 0 0;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div style="width:32px;height:32px;border-radius:8px;background:#0f172a;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2M9 1h6M12 5V1"/></svg>
                        </div>
                        <div style="line-height:1.3;">
                            <div style="font-size:11px;color:var(--sw-text-muted,#94a3b8);font-variant-numeric:tabular-nums;">{$startLabel} → {$endLabel}</div>
                            <div style="font-size:11px;color:var(--sw-text-muted,#94a3b8);font-variant-numeric:tabular-nums;">{$count} checkpoints</div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:4px;align-self:center;">
                        <button type="button" class="sw-copy" aria-label="Copy profile as Markdown" title="Copy profile as Markdown" style="display:none;">
                            <span class="sw-copy-idle">{$iconCopy}</span>
                            <span class="sw-copy-done">{$iconCheck}</span>
                        </button>
                        <button type="button" class="sw-theme-toggle" aria-label="Toggle color scheme" title="Toggle color scheme" style="display:none;">
                            <span class="sw-theme-toggle-light">{$iconSun}</span>
                            <span class="sw-theme-toggle-dark">{$iconMoon}</span>
                        </button>
                    </div>
                    <div style="text-align:right;line-height:1;">
                        <div style="display:inline-flex;align-items:center;gap:7px;font-weight:600;font-size:22px;font-variant-numeric:tabular-nums;letter-spacing:-0.01em;color:var(--sw-text,#0f172a);">
                            <span style="color:var(--sw-text-muted,#94a3b8);display:inline-flex;">{$totalIcon}</span>{$totalLabel}
                        </div>
                        <div style="font-size:10px;color:var(--sw-text-muted,#94a3b8);text-transform:uppercase;letter-spacing:0.08em;margin-top:4px;">Total</div>
                    </div>
                </div>
                <div style="margin-top:14px;">
                    <div style="display:flex;height:8px;border-radius:3px;background:var(--sw-border,#f1f5f9);position:relative;">{$segments}</div>
                    <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--sw-text-muted,#94a3b8);margin-top:5px;font-variant-numeric:tabular-nums;">
                        <span title="Stopwatch start" style="cursor:help;">0ms</span><span title="Total duration" style="cursor:help;">{$totalLabel}</span>
                    </div>
                </div>
            </header>
            {$body}
            <footer style="padding:10px 18px 12px;font-size:11px;color:var(--sw-text-muted,#94a3b8);font-variant-numeric:tabular-nums;display:flex;justify-content:space-between;align-items:center;gap:12px;">
                <div>{$totalsLabel}</div>
                <div>{$tailLabel}</div>
            </footer>
            <script>{$inlineScript}</script>
        </div>
        HTML;
    }

    /**
     * @param array{queries: int, queryMs: float, memoryDelta: int, hasQueries: bool, hasMemory: bool} $totals
     */
    private static function renderTotals(array $totals): string
    {
        $bits = [];

        if ($totals['hasQueries']) {
            $bits[] = '<span style="color:var(--sw-query-color,#7c3aed);display:inline-flex;align-items:center;gap:4px;">'
                . StopwatchIcons::db()
                . $totals['queries'] . 'q · ' . Stopwatch::formatDuration($totals['queryMs'])
                . '</span>';
        }

        if ($totals['hasMemory']) {
            $bits[] = '<span style="color:#64748b;display:inline-flex;align-items:center;gap:4px;">'
                . StopwatchIcons::memory()
                . e(StopwatchCheckpoint::formatMemoryDelta($totals['memoryDelta']))
                . '</span>';
        }

        if ($bits === []) {
            return '';
        }

        return '<div style="display:flex;align-items:center;gap:10px;">'
            . implode('<span style="color:var(--sw-text-dim,#cbd5e1);">·</span>', $bits)
            . '</div>';
    }

    private static function styleBlock(): string
    {
        $darkVars = self::DARK_VARS;
        $darkFilter = self::DARK_FILTER;

        return <<<HTML
            <style>
                .sw-stopwatch {
                    --sw-bg: #fff;
                    --sw-text: #0f172a;
                    --sw-text-muted: #94a3b8;
                    --sw-text-dim: #cbd5e1;
                    --sw-border: #f1f5f9;
                    --sw-chip-bg: #f1f5f9;
                    --sw-chip-key: #94a3b8;
                    --sw-chip-val: #334155;
                    --sw-hover-bg: #eef2f7;
                    --sw-tip-bg: #0f172a;
                    --sw-tip-text: #cbd5e1;
                    --sw-tip-label: #fff;
                    --sw-tip-mute: #94a3b8;
                    --sw-tip-divider: rgba(255,255,255,.08);
                    --sw-mem-color: #94a3b8;
                    --sw-mem-color-small: #cbd5e1;
                    --sw-query-color: #7c3aed;
                    --sw-active-ring: #fff;
                }
                @media (prefers-color-scheme: dark) {
                    .sw-stopwatch:not([data-theme="light"]) { {$darkVars} }
                    .sw-stopwatch:not([data-theme="light"]) .sw-dot,
                    .sw-stopwatch:not([data-theme="light"]) .sw-bar-fill,
                    .sw-stopwatch:not([data-theme="light"]) .sw-seg { {$darkFilter} }
                }
                /* Manual override via data-theme="dark" — wins regardless of system preference. */
                .sw-stopwatch[data-theme="dark"] { {$darkVars} }
                .sw-stopwatch[data-theme="dark"] .sw-dot,
                .sw-stopwatch[data-theme="dark"] .sw-bar-fill,
                .sw-stopwatch[data-theme="dark"] .sw-seg { {$darkFilter} }
                /* Header action buttons (copy + theme toggle) — hidden until JS marks the card
                   with .sw-js so non-JS users don't see dead buttons. */
                .sw-stopwatch .sw-theme-toggle,
                .sw-stopwatch .sw-copy { display: none; }
                .sw-stopwatch.sw-js .sw-theme-toggle,
                .sw-stopwatch.sw-js .sw-copy {
                    width: 24px; height: 24px; padding: 0; border: 0; background: transparent;
                    color: var(--sw-text-muted,#94a3b8); cursor: pointer; border-radius: 6px;
                    display: inline-flex; align-items: center; justify-content: center;
                    transition: background 140ms ease, color 140ms ease;
                }
                .sw-stopwatch .sw-theme-toggle:hover,
                .sw-stopwatch .sw-copy:hover { background: var(--sw-hover-bg,#eef2f7); color: var(--sw-text,#0f172a); }
                .sw-stopwatch .sw-theme-toggle .sw-theme-toggle-light,
                .sw-stopwatch .sw-theme-toggle .sw-theme-toggle-dark,
                .sw-stopwatch .sw-copy .sw-copy-done { display: none; }
                .sw-stopwatch .sw-copy.sw-copied .sw-copy-idle { display: none; }
                .sw-stopwatch .sw-copy.sw-copied .sw-copy-done { display: inline-flex; color: #16a34a; }
                .sw-stopwatch[data-theme="light"] .sw-theme-toggle .sw-theme-toggle-dark { display: inline-flex; }
                .sw-stopwatch[data-theme="dark"]  .sw-theme-toggle .sw-theme-toggle-light { display: inline-flex; }
                @media (prefers-color-scheme: light) {
                    .sw-stopwatch:not([data-theme]) .sw-theme-toggle .sw-theme-toggle-dark { display: inline-flex; }
                }
                @media (prefers-color-scheme: dark) {
                    .sw-stopwatch:not([data-theme]) .sw-theme-toggle .sw-theme-toggle-light { display: inline-flex; }
                }
                .sw-stopwatch .sw-row { transition: background 140ms ease, box-shadow 140ms ease; }
                .sw-stopwatch .sw-row::after {
                    content: ""; position: absolute; bottom: 0; left: 18px; right: 18px;
                    height: 1px; background: var(--sw-border,#f1f5f9); pointer-events: none;
                }
                .sw-stopwatch .sw-row:last-child::after,
                .sw-stopwatch .sw-slow::after,
                .sw-stopwatch .sw-slow + .sw-row::after { display: none; }
                .sw-stopwatch .sw-slow::before {
                    content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 3px;
                    background: var(--sw-stripe, #dc2626); pointer-events: none;
                }
                .sw-stopwatch .sw-row:hover,
                .sw-stopwatch .sw-row:focus-visible,
                .sw-stopwatch .sw-row.sw-active {
                    background: var(--sw-hover-bg,#eef2f7);
                    box-shadow: inset 3px 0 0 var(--sw-bar);
                    outline: none;
                }
                .sw-stopwatch .sw-slow:hover,
                .sw-stopwatch .sw-slow:focus-visible,
                .sw-stopwatch .sw-slow.sw-active {
                    background: var(--sw-slow-bg, #fef2f2);
                    box-shadow: none;
                    /* Slow hover bg is always light pink — pin text/chips dark for contrast in any color scheme. */
                    --sw-text: #0f172a;
                    --sw-chip-bg: #fff;
                    --sw-chip-key: #94a3b8;
                    --sw-chip-val: #334155;
                }
                /* Active segment grows + glows. The segment is always position:relative so the
                   tip's containing block stays the segment regardless of whether a transform is
                   in play — JS positions the tip in seg-relative coordinates. */
                .sw-stopwatch .sw-seg {
                    transition: filter 140ms ease, box-shadow 140ms ease, transform 140ms ease;
                    transform-origin: center;
                }
                .sw-stopwatch .sw-seg:hover,
                .sw-stopwatch .sw-seg:focus-visible,
                .sw-stopwatch .sw-seg.sw-active {
                    filter: brightness(1.25) saturate(1.25);
                    box-shadow: 0 0 0 2px var(--sw-active-ring,#fff);
                    transform: scaleY(1.5);
                    z-index: 2;
                }
                .sw-stopwatch .sw-bar-fill {
                    transform-origin: left center;
                    animation: sw-bar-in 520ms cubic-bezier(.22,.61,.36,1) backwards;
                    transition: box-shadow 160ms ease, filter 160ms ease;
                }
                .sw-stopwatch .sw-row:hover .sw-bar-fill,
                .sw-stopwatch .sw-row:focus-visible .sw-bar-fill,
                .sw-stopwatch .sw-row.sw-active .sw-bar-fill {
                    box-shadow: 0 0 0 1px var(--sw-bar), 0 0 8px var(--sw-bar);
                    filter: brightness(1.05);
                }
                @keyframes sw-bar-in { from { transform: scaleX(0); } to { transform: scaleX(1); } }
                @media (prefers-reduced-motion: reduce) {
                    .sw-stopwatch .sw-bar-fill { animation: none; }
                    .sw-stopwatch .sw-row, .sw-stopwatch .sw-bar-fill { transition: none; }
                }
                .sw-stopwatch .sw-tip {
                    position: absolute; bottom: calc(100% - 4px); right: 14px; z-index: 10;
                    min-width: 200px;
                    max-width: min(280px, calc(100vw - 40px));
                    padding: 9px 11px;
                    background: var(--sw-tip-bg,#0f172a); color: var(--sw-tip-text,#cbd5e1);
                    border-radius: 7px; font-size: 11px; font-weight: 400; line-height: 1.55;
                    text-align: left;
                    box-shadow: 0 10px 28px -6px rgba(15,23,42,.5), 0 0 0 1px rgba(255,255,255,.04);
                    opacity: 0; transform: translateY(4px); pointer-events: none;
                    transition: opacity 130ms ease, transform 130ms ease;
                }
                .sw-stopwatch .sw-tip::after {
                    content: ""; position: absolute; top: 100%; right: 18px;
                    border: 5px solid transparent; border-top-color: var(--sw-tip-bg,#0f172a);
                }
                .sw-stopwatch .sw-row:hover .sw-tip,
                .sw-stopwatch .sw-row:focus-visible .sw-tip,
                .sw-stopwatch .sw-row.sw-active .sw-tip {
                    /* Default display:none is inlined on .sw-tip so the tip stays hidden in mail
                       clients that strip <style>. Override here when actually interacting. */
                    display: block !important;
                    opacity: 1; transform: translateY(0); transition-delay: 120ms;
                }
                /* Flip tip below row for top rows so it doesn't clip the scroll viewport
                   or collide with the overview-bar segment tip dropping down from the header. */
                .sw-stopwatch .sw-row:first-child .sw-tip,
                .sw-stopwatch .sw-row:nth-child(2) .sw-tip,
                .sw-stopwatch .sw-row:nth-child(3) .sw-tip {
                    bottom: auto; top: calc(100% - 4px); transform: translateY(-4px);
                }
                .sw-stopwatch .sw-row:first-child:hover .sw-tip,
                .sw-stopwatch .sw-row:nth-child(2):hover .sw-tip,
                .sw-stopwatch .sw-row:nth-child(3):hover .sw-tip,
                .sw-stopwatch .sw-row:first-child:focus-visible .sw-tip,
                .sw-stopwatch .sw-row:nth-child(2):focus-visible .sw-tip,
                .sw-stopwatch .sw-row:nth-child(3):focus-visible .sw-tip,
                .sw-stopwatch .sw-row:first-child.sw-active .sw-tip,
                .sw-stopwatch .sw-row:nth-child(2).sw-active .sw-tip,
                .sw-stopwatch .sw-row:nth-child(3).sw-active .sw-tip {
                    transform: translateY(0);
                }
                .sw-stopwatch .sw-row:first-child .sw-tip::after,
                .sw-stopwatch .sw-row:nth-child(2) .sw-tip::after,
                .sw-stopwatch .sw-row:nth-child(3) .sw-tip::after {
                    top: auto; bottom: 100%;
                    border-top-color: transparent; border-bottom-color: var(--sw-tip-bg,#0f172a);
                }
                .sw-stopwatch .sw-seg { cursor: default; }
                .sw-stopwatch .sw-seg:focus-visible {
                    outline: 2px solid var(--sw-active-ring,#fff);
                    outline-offset: -2px;
                }
                /* Segment tooltips are siblings of segments inside the bar (not children) so
                   segment transforms don't visually scale the tip. JS positions them on hover.
                   Single-line layout so the tip is shorter + wider — easier to scan inline. */
                .sw-stopwatch .sw-seg-tip {
                    position: absolute;
                    bottom: auto; top: calc(100% + 8px);
                    left: 0; right: auto;
                    transform: translateY(4px);
                    min-width: 0;
                    max-width: min(320px, calc(100vw - 40px));
                }
                .sw-stopwatch .sw-seg-tip.sw-show {
                    display: block !important;
                    opacity: 1;
                    transform: translateY(0);
                }
                /* Print: strip interactive chrome and let the card flow naturally. */
                @media print {
                    .sw-stopwatch {
                        max-width: none !important;
                        box-shadow: none !important;
                        break-inside: avoid;
                    }
                    .sw-stopwatch .sw-tip,
                    .sw-stopwatch .sw-theme-toggle { display: none !important; }
                    .sw-stopwatch .sw-bar-fill { animation: none !important; }
                    .sw-stopwatch > div[style*="max-height"] {
                        max-height: none !important;
                        overflow: visible !important;
                    }
                }
                .sw-stopwatch .sw-seg-tip::after {
                    top: auto; bottom: 100%;
                    right: auto; left: var(--sw-arrow-left, 50%); margin-left: -5px;
                    border-top-color: transparent; border-bottom-color: var(--sw-tip-bg,#0f172a);
                }
            </style>
            HTML;
    }
}
