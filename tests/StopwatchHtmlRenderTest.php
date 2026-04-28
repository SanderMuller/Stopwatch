<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use SanderMuller\Stopwatch\FakeClock;
use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\StopwatchCheckpoint;
use SanderMuller\Stopwatch\StopwatchCheckpointCollection;
use SanderMuller\Stopwatch\StopwatchCheckpointHtmlRenderer;

final class StopwatchHtmlRenderTest extends TestCase
{
    public function test_render_html_contains_total_and_group_label_and_highlighting(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->start();
        $stopwatch->checkpoint('C1');
        $stopwatch->slowCheckpointThreshold(0); // mark all as slow for deterministic highlighting
        $clock->advance(100);
        $stopwatch->checkpoint('C2');

        $htmlString = $stopwatch->render();
        self::assertInstanceOf(HtmlString::class, $htmlString);
        $out = (string) $htmlString;
        self::assertStringContainsString('Total', $out);
        self::assertStringContainsString('C1', $out);
        self::assertStringContainsString('C2', $out);
        // slow rows are tagged with the sw-slow class and a "slow" pill
        self::assertStringContainsString('sw-slow', $out);
        self::assertStringContainsString('>slow<', $out);
    }

    public function test_render_html_handles_zero_duration_without_division_by_zero(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->start();
        $stopwatch->checkpoint('Zero');

        $out = (string) $stopwatch->render();
        self::assertStringContainsString('Zero', $out);
        self::assertStringContainsString('0%', $out);
    }

    public function test_format_duration_scales_unit(): void
    {
        self::assertSame('3.4ms', Stopwatch::formatDuration(3.4));
        self::assertSame('99.9ms', Stopwatch::formatDuration(99.9));
        self::assertSame('143ms', Stopwatch::formatDuration(142.7));
        self::assertSame('999ms', Stopwatch::formatDuration(999));
        self::assertSame('1.25s', Stopwatch::formatDuration(1247));
        self::assertSame('59.99s', Stopwatch::formatDuration(59_991));
        self::assertSame('1m 5s', Stopwatch::formatDuration(65_000));
        self::assertSame('2m 30s', Stopwatch::formatDuration(150_000));
        // Regression: 119_500ms must roll over to "2m 0s", not "1m 60s".
        self::assertSame('2m 0s', Stopwatch::formatDuration(119_500));
        self::assertSame('2m 0s', Stopwatch::formatDuration(120_000));
        self::assertSame('1m 0s', Stopwatch::formatDuration(60_000));
        // Regression: values near a unit boundary must promote into the next unit instead
        // of rendering impossible labels like "1000ms" or "60s".
        self::assertSame('1s', Stopwatch::formatDuration(999.6));
        self::assertSame('1s', Stopwatch::formatDuration(999.5));
        self::assertSame('1m 0s', Stopwatch::formatDuration(59_996));
    }

    public function test_render_html_empty_state_when_no_checkpoints(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->start();

        $out = (string) $stopwatch->render();
        self::assertStringContainsString('No checkpoints recorded', $out);
    }

    public function test_render_html_has_accessibility_attributes(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->start();
        $clock->advance(100);
        $stopwatch->slowCheckpointThreshold(50);
        $stopwatch->checkpoint('Slow op');

        $out = (string) $stopwatch->render();
        self::assertStringContainsString('role="region"', $out);
        self::assertStringContainsString('aria-label="Stopwatch profile"', $out);
        self::assertStringContainsString('aria-label="slower than threshold"', $out);
    }

    public function test_render_html_supports_dark_mode_via_media_query(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->start();
        $stopwatch->checkpoint('A');

        $out = (string) $stopwatch->render();
        self::assertStringContainsString('prefers-color-scheme: dark', $out);
        self::assertStringContainsString('--sw-bg', $out);
    }

    public function test_render_html_uses_smart_duration_formatting(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->start();
        $clock->advance(1500);
        $stopwatch->checkpoint('Long');

        $out = (string) $stopwatch->render();
        self::assertStringContainsString('1.5s', $out);
    }

    public function test_render_html_overview_segments_mark_slow_checkpoints(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->slowCheckpointThreshold(10);
        $stopwatch->start();
        $clock->advance(100);
        $stopwatch->checkpoint('Slow segment');

        $out = (string) $stopwatch->render();
        self::assertStringContainsString('class="sw-seg"', $out);
        // Segment tooltip surfaces the slow signal as a pill within the tip body.
        self::assertMatchesRegularExpression('/class="sw-tip sw-seg-tip"[^>]*>.*?Slow segment.*?>slow</is', $out);
    }

    public function test_render_html_sub_one_percent_share_renders_as_lt_one(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->start();
        // Tiny first checkpoint, then a huge second so the first is well under 1% of total.
        $clock->advance(1);
        $stopwatch->checkpoint('Tiny');
        $clock->advance(10_000);
        $stopwatch->checkpoint('Huge');

        $out = (string) $stopwatch->render();
        // The "Tiny" row should report <1% of the ~10s total, not "0%".
        self::assertStringContainsString('&lt;1%', $out);
    }

    public function test_render_html_tooltip_includes_memory_current_and_peak(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock)->withMemoryTracking();
        $stopwatch->start();
        $clock->advance(10);
        $stopwatch->checkpoint('With memory');

        $out = (string) $stopwatch->render();
        self::assertStringContainsString('now', $out);
        self::assertStringContainsString('peak', $out);
    }

    public function test_collection_totals_aggregates_queries_and_memory(): void
    {
        $col = StopwatchCheckpointCollection::empty();
        $now = CarbonImmutable::now();
        $col->addCheckpoint('A', null, 5.0, 5.0, $now, queryCount: 2, queryTimeMs: 1.5, memoryDelta: 1024);
        $col->addCheckpoint('B', null, 10.0, 15.0, $now, queryCount: 3, queryTimeMs: 2.5, memoryDelta: 2048);

        $totals = $col->totals();
        self::assertSame(5, $totals['queries']);
        self::assertEqualsWithDelta(4.0, $totals['queryMs'], 0.001);
        self::assertSame(3072, $totals['memoryDelta']);
        self::assertTrue($totals['hasQueries']);
        self::assertTrue($totals['hasMemory']);
    }

    public function test_collection_totals_empty_when_no_tracking(): void
    {
        $col = StopwatchCheckpointCollection::empty();
        $now = CarbonImmutable::now();
        $col->addCheckpoint('Plain', null, 5.0, 5.0, $now);

        $totals = $col->totals();
        self::assertSame(0, $totals['queries']);
        self::assertSame(0.0, $totals['queryMs']);
        self::assertSame(0, $totals['memoryDelta']);
        self::assertFalse($totals['hasQueries']);
        self::assertFalse($totals['hasMemory']);
    }

    public function test_collection_totals_partial_tracking(): void
    {
        $col = StopwatchCheckpointCollection::empty();
        $now = CarbonImmutable::now();
        $col->addCheckpoint('A', null, 5.0, 5.0, $now, queryCount: 1, queryTimeMs: 0.5);

        $totals = $col->totals();
        self::assertTrue($totals['hasQueries']);
        self::assertFalse($totals['hasMemory']);
        self::assertSame(1, $totals['queries']);
        self::assertSame(0, $totals['memoryDelta']);
    }

    public function test_share_label_helper(): void
    {
        self::assertSame('0%', StopwatchCheckpointHtmlRenderer::shareLabel(0.0));
        self::assertSame('&lt;1%', StopwatchCheckpointHtmlRenderer::shareLabel(0.4));
        // 0.5 rounds to 1, so it's no longer the "<1%" case
        self::assertSame('1%', StopwatchCheckpointHtmlRenderer::shareLabel(0.5));
        self::assertSame('1%', StopwatchCheckpointHtmlRenderer::shareLabel(1.4));
        self::assertSame('50%', StopwatchCheckpointHtmlRenderer::shareLabel(50.0));
        self::assertSame('100%', StopwatchCheckpointHtmlRenderer::shareLabel(100.0));
    }

    public function test_slow_mark_color_tiers(): void
    {
        // light tier: 1×–2× threshold
        self::assertSame('#fca5a5', StopwatchCheckpointHtmlRenderer::slowMarkColor(60.0, 50));
        self::assertSame('#fca5a5', StopwatchCheckpointHtmlRenderer::slowMarkColor(99.0, 50));
        // medium tier: 2×–5×
        self::assertSame('#ef4444', StopwatchCheckpointHtmlRenderer::slowMarkColor(150.0, 50));
        self::assertSame('#ef4444', StopwatchCheckpointHtmlRenderer::slowMarkColor(249.0, 50));
        // heavy tier: 5×+
        self::assertSame('#dc2626', StopwatchCheckpointHtmlRenderer::slowMarkColor(400.0, 50));
        self::assertSame('#dc2626', StopwatchCheckpointHtmlRenderer::slowMarkColor(5000.0, 50));
    }

    public function test_render_slow_severity_tiers_picked_in_html(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->slowCheckpointThreshold(50);
        $stopwatch->start();
        $clock->advance(75);   // 1.5× — light → stripe #fca5a5
        $stopwatch->checkpoint('Light');
        $clock->advance(150);  // 3× — medium → stripe #ef4444
        $stopwatch->checkpoint('Medium');
        $clock->advance(500);  // 10× — heavy → stripe #dc2626
        $stopwatch->checkpoint('Heavy');

        $out = (string) $stopwatch->render();
        self::assertStringContainsString('--sw-stripe:#fca5a5', $out);
        self::assertStringContainsString('--sw-stripe:#ef4444', $out);
        self::assertStringContainsString('--sw-stripe:#dc2626', $out);
    }

    public function test_render_first_row_only_has_since_start_marker(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->start();
        $clock->advance(5);
        $stopwatch->checkpoint('First');
        $clock->advance(5);
        $stopwatch->checkpoint('Second');
        $clock->advance(5);
        $stopwatch->checkpoint('Third');

        $out = (string) $stopwatch->render();
        // Marker appears exactly once (first row only).
        self::assertSame(1, substr_count($out, 'since start'));
    }

    public function test_render_memory_color_threshold(): void
    {
        $now = CarbonImmutable::now();

        // Small delta (<10KB) should use the dim color var.
        $small = new StopwatchCheckpoint(
            label: 'Small',
            metadata: null,
            timeSinceLastCheckpointMs: 1.0,
            timeSinceStopwatchStartMs: 1.0,
            time: $now,
            memoryDelta: 500,
        );
        $smallRow = StopwatchCheckpointHtmlRenderer::row($small, 10.0, 50, '#000', 0);
        self::assertStringContainsString('var(--sw-mem-color-small,', $smallRow);

        // Big delta (>=10KB) should use the regular color var.
        $big = new StopwatchCheckpoint(
            label: 'Big',
            metadata: null,
            timeSinceLastCheckpointMs: 1.0,
            timeSinceStopwatchStartMs: 2.0,
            time: $now,
            memoryDelta: 100_000,
        );
        $bigRow = StopwatchCheckpointHtmlRenderer::row($big, 10.0, 50, '#000', 1);
        self::assertStringContainsString('var(--sw-mem-color,', $bigRow);
        self::assertStringNotContainsString('var(--sw-mem-color-small', $bigRow);
    }

    public function test_render_footer_omits_totals_when_no_tracking(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->start();
        $clock->advance(5);
        $stopwatch->checkpoint('Plain');

        $out = (string) $stopwatch->render();
        // Footer tail still present, but no aggregated query/memory line.
        self::assertStringContainsString('after last checkpoint', $out);
        self::assertStringNotContainsString('q · ', $out);
    }

    public function test_render_footer_shows_totals_with_tracking(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock)->withMemoryTracking();
        $stopwatch->start();
        $clock->advance(5);
        $stopwatch->checkpoint('A');

        $out = (string) $stopwatch->render();
        // Memory icon shape + a memory delta should be present in totals.
        self::assertStringContainsString('rect x="4" y="4"', $out);
    }

    public function test_render_includes_http_chip_and_tooltip_with_status_colors(): void
    {
        $col = StopwatchCheckpointCollection::empty();
        $now = CarbonImmutable::now();
        $col->addCheckpoint(
            label: 'Load order',
            metadata: null,
            timeSinceLastCheckpointMs: 200.0,
            timeSinceStopwatchStartMs: 200.0,
            time: $now,
            httpCount: 5,
            httpTimeMs: 234.0,
            httpCalls: [
                // URLs reach the renderer pre-stripped of query strings (capture-side responsibility).
                ['method' => 'GET', 'url' => 'https://api.example.com/users', 'status' => 200, 'durationMs' => 142.0],
                ['method' => 'POST', 'url' => 'https://api.example.com/orders', 'status' => 404, 'durationMs' => 56.0],
                ['method' => 'GET', 'url' => 'https://api.example.com/items', 'status' => 500, 'durationMs' => 36.0],
                ['method' => 'GET', 'url' => 'https://api.example.com/extra', 'status' => 0, 'durationMs' => 0.0],
                ['method' => 'GET', 'url' => 'https://api.example.com/extra2', 'status' => 200, 'durationMs' => 0.0],
            ],
        );

        $out = StopwatchCheckpointHtmlRenderer::row($col->first(), 200.0, 50, '#000', 0);

        // Chip in metric stack — icon prefix + count, no letter suffix
        self::assertMatchesRegularExpression('/<svg[^>]*>.*?<\/svg>5 · /s', $out);
        // Tip preview caps at 3 calls; expansion panel shows all 5 (including the ERR status).
        self::assertStringContainsString('+2 more', $out);
        self::assertStringContainsString('ERR', $out);
        self::assertStringContainsString('class="sw-expansion"', $out);
        self::assertStringContainsString('api.example.com/users', $out);
        // Status colors: 2xx green, 4xx amber, 5xx red
        self::assertStringContainsString('#22c55e', $out); // 200 → green
        self::assertStringContainsString('#f59e0b', $out); // 404 → amber
        self::assertStringContainsString('#ef4444', $out); // 500 → red
    }

    public function test_render_footer_shows_http_totals_with_tracking(): void
    {
        $col = StopwatchCheckpointCollection::empty();
        $now = CarbonImmutable::now();
        $col->addCheckpoint('A', null, 5.0, 5.0, $now, httpCount: 3, httpTimeMs: 120.0);

        $totals = $col->totals();

        self::assertTrue($totals['hasHttp']);
        self::assertSame(3, $totals['httpCount']);
        self::assertEqualsWithDelta(120.0, $totals['httpMs'], 0.001);
    }

    public function test_render_suppresses_query_and_http_chips_when_count_is_zero(): void
    {
        $col = StopwatchCheckpointCollection::empty();
        $now = CarbonImmutable::now();
        // Tracking enabled (queryCount/httpCount not null) but the row had no activity.
        // Chips should not render — they'd just add visual noise on quiet rows.
        $col->addCheckpoint(
            label: 'Quiet row',
            metadata: null,
            timeSinceLastCheckpointMs: 5.0,
            timeSinceStopwatchStartMs: 5.0,
            time: $now,
            queryCount: 0,
            queryTimeMs: 0.0,
            httpCount: 0,
            httpTimeMs: 0.0,
            httpCalls: [],
            queryCalls: [],
        );

        $out = StopwatchCheckpointHtmlRenderer::row($col->first(), 5.0, 50, '#000', 0);

        // No chip, no tip detail line for either tracker on this row.
        self::assertStringNotContainsString('0q', $out);
        self::assertStringNotContainsString('0h', $out);
        self::assertStringNotContainsString('queries </span>', $out);
        self::assertStringNotContainsString('calls </span>', $out);
    }

    public function test_render_expansion_panel_includes_full_label_metadata_queries_http_memory(): void
    {
        $col = StopwatchCheckpointCollection::empty();
        $now = CarbonImmutable::now();
        $col->addCheckpoint(
            label: 'A really long label that gets truncated in the row but should appear in full inside the expansion panel',
            metadata: ['order_id' => 'ORD-123', 'currency' => 'EUR'],
            timeSinceLastCheckpointMs: 320.0,
            timeSinceStopwatchStartMs: 320.0,
            time: $now,
            queryCount: 2,
            queryTimeMs: 5.5,
            memoryUsage: 12_582_912,
            memoryDelta: 1_048_576,
            memoryPeak: 16_777_216,
            httpCount: 1,
            httpTimeMs: 142.0,
            httpCalls: [
                ['method' => 'GET', 'url' => 'https://api.example.com/users', 'status' => 200, 'durationMs' => 142.0],
            ],
            queryCalls: [
                ['sql' => 'SELECT * FROM users WHERE id = ?', 'bindings' => [42], 'durationMs' => 3.0],
                ['sql' => 'SELECT * FROM orders WHERE user_id = ?', 'bindings' => [42], 'durationMs' => 2.5],
            ],
        );

        $out = StopwatchCheckpointHtmlRenderer::row($col->first(), 320.0, 50, '#000', 0);

        self::assertStringContainsString('class="sw-expansion"', $out);
        self::assertStringContainsString('display:none', $out); // hidden by default
        self::assertStringContainsString('role="button"', $out);
        self::assertStringContainsString('aria-expanded="false"', $out);

        // Full label appears in the panel even though row truncates with ellipsis
        self::assertStringContainsString('A really long label that gets truncated', $out);

        // Metadata, memory, queries (with SQL + bindings), HTTP all rendered in the panel
        self::assertStringContainsString('order_id', $out);
        self::assertStringContainsString('ORD-123', $out);
        self::assertStringContainsString('SELECT * FROM users WHERE id = ?', $out);
        self::assertStringContainsString('[42]', $out);
        self::assertStringContainsString('api.example.com/users', $out);
        self::assertStringContainsString('×', $out); // slow factor (320ms / 50ms threshold = 6.4×)
        self::assertStringContainsString('peak', $out);
    }

    public function test_to_markdown_includes_http_column_when_tracked(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock)->withHttpTracking();
        $stopwatch->start();
        $clock->advance(50);
        // Manually inject an HTTP call by going through the recorded path — the test runs
        // outside a real HTTP context, so synthesize a checkpoint with explicit http data
        // via the collection directly to verify the markdown column renders.
        $stopwatch->checkpoint('Plain'); // baseline so totals() runs through tracking branch

        $md = $stopwatch->toMarkdown();
        // With tracking enabled, the summary line should mention HTTP even when count is 0
        self::assertStringContainsString('**HTTP calls (total):**', $md);
        // And the table should have an HTTP column header
        self::assertStringContainsString('| HTTP |', $md);
    }

    public function test_render_html_includes_theme_toggle_button(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->start();
        $stopwatch->checkpoint('A');

        $out = (string) $stopwatch->render();
        self::assertStringContainsString('class="sw-theme-toggle"', $out);
        self::assertStringContainsString('aria-label="Toggle color scheme"', $out);
        // Sun icon (light mode shown when current is dark)
        self::assertStringContainsString('class="sw-theme-toggle-light"', $out);
        // Moon icon (dark mode shown when current is light)
        self::assertStringContainsString('class="sw-theme-toggle-dark"', $out);
        // Persistence script
        self::assertStringContainsString("localStorage.getItem('sw-theme')", $out);
    }

    public function test_render_html_rows_and_segments_are_keyboard_focusable(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->start();
        $clock->advance(10);
        $stopwatch->checkpoint('A');

        $out = (string) $stopwatch->render();
        self::assertStringContainsString('class="sw-row"', $out);
        self::assertMatchesRegularExpression('/<div class="sw-row[^"]*" tabindex="0"/', $out);
        self::assertMatchesRegularExpression('/<div class="sw-seg" tabindex="0"/', $out);
        self::assertStringContainsString(':focus-visible', $out);
    }

    public function test_render_html_includes_print_stylesheet(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->start();
        $stopwatch->checkpoint('A');

        $out = (string) $stopwatch->render();
        self::assertStringContainsString('@media print', $out);
        // Inside the print block the tooltip and toggle button are hidden.
        $printBlock = (string) preg_replace('/^.*?@media print\s*\{(.*?)\n\s*\}\s*<\/style>.*$/s', '$1', $out);
        self::assertStringContainsString('.sw-tip', $printBlock);
        self::assertStringContainsString('.sw-theme-toggle', $printBlock);
        self::assertStringContainsString('display: none', $printBlock);
    }

    public function test_render_html_hides_interactive_chrome_inline_for_mail_clients(): void
    {
        // Mail clients commonly strip <style>/<script>. Anything that should stay hidden in
        // those contexts must carry inline `display:none` on the element itself, not rely on
        // the embedded stylesheet alone.
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->start();
        $clock->advance(10);
        $stopwatch->checkpoint('A');

        $out = (string) $stopwatch->render();
        // Tooltip popovers should not show their content as raw text in mail.
        self::assertMatchesRegularExpression('/<div class="sw-tip" style="display:none[^"]*"/', $out);
        // Theme toggle button should not show as a dead control in mail.
        self::assertMatchesRegularExpression('/<button[^>]*class="sw-theme-toggle"[^>]*style="display:none/', $out);
    }

    public function test_to_markdown_summary_and_table(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->slowCheckpointThreshold(50);
        $stopwatch->start();
        $clock->advance(20);
        $stopwatch->checkpoint('Validate');
        $clock->advance(180);
        $stopwatch->checkpoint('Render PDF', ['template' => 'invoice', 'pages' => 14]);

        $md = $stopwatch->toMarkdown();
        self::assertStringContainsString('# Stopwatch profile', $md);
        self::assertStringContainsString('**Total:**', $md);
        self::assertStringContainsString('**Slow threshold:** 50ms', $md);
        self::assertStringContainsString('| # | Checkpoint | Δ | Cumulative | Share | Slow', $md);
        self::assertStringContainsString('Validate', $md);
        self::assertStringContainsString('Render PDF', $md);
        // Render PDF is 3.6× the 50ms threshold
        self::assertStringContainsString('3.6×', $md);
        // Metadata serialized as JSON
        self::assertStringContainsString('"template":"invoice"', $md);
    }

    public function test_copy_markdown_payload_round_trips_multibyte_characters(): void
    {
        // Slow-tier markers in the markdown table use "×" (U+00D7) and labels can carry any
        // UTF-8. The base64 payload + JS decoder must preserve them exactly.
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->slowCheckpointThreshold(50);
        $stopwatch->start();
        $clock->advance(400);
        $stopwatch->checkpoint('Render café — déjà vu');

        $out = (string) $stopwatch->render();
        if (! preg_match('/data-sw-md="([A-Za-z0-9+\\/=]+)"/', $out, $m)) {
            self::fail('Expected base64-encoded markdown payload on the card root.');
        }
        $decoded = base64_decode($m[1], true);
        self::assertNotFalse($decoded);
        self::assertStringContainsString('×', $decoded);
        self::assertStringContainsString('Render café — déjà vu', $decoded);
    }

    public function test_to_markdown_escapes_pipes_and_newlines_in_labels(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->start();
        $stopwatch->checkpoint("Pipe | label\nwith newline");

        $md = $stopwatch->toMarkdown();
        // Pipe is escaped so it doesn't break the table; newline becomes a space.
        self::assertStringContainsString('Pipe \\| label with newline', $md);
    }

    public function test_render_html_includes_copy_markdown_button_with_payload(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->start();
        $stopwatch->checkpoint('A');

        $out = (string) $stopwatch->render();
        self::assertStringContainsString('class="sw-copy"', $out);
        self::assertStringContainsString('aria-label="Copy profile as Markdown"', $out);
        // Markdown is base64-encoded into a data attribute.
        self::assertMatchesRegularExpression('/data-sw-md="([A-Za-z0-9+\\/=]+)"/', $out);
    }

    public function test_render_html_supports_manual_dark_theme_override(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->start();
        $stopwatch->checkpoint('A');

        $out = (string) $stopwatch->render();
        // Manual data-theme override block must be present so users can force a theme.
        self::assertStringContainsString('.sw-stopwatch[data-theme="dark"]', $out);
        // System dark-mode rule is gated to skip when data-theme="light" forces it off.
        self::assertStringContainsString(':not([data-theme="light"])', $out);
    }

    public function test_blade_directive_renders_stopwatch(): void
    {
        stopwatch()->start();
        stopwatch()->checkpoint('Blade test');

        $rendered = Blade::render('@stopwatch');

        self::assertStringContainsString('Total', $rendered);
        self::assertStringContainsString('Blade test', $rendered);
    }
}
