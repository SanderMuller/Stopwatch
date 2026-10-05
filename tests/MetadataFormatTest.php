<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use SanderMuller\Stopwatch\FakeClock;
use SanderMuller\Stopwatch\Integrations\DebugbarCollector;
use SanderMuller\Stopwatch\RunLog\DetailRenderer;
use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\StopwatchCheckpoint;
use SanderMuller\Stopwatch\StopwatchOutput;
use stdClass;
use Symfony\Component\VarDumper\VarDumper;

final class MetadataFormatTest extends TestCase
{
    public function test_non_scalar_metadata_renders_as_compact_json(): void
    {
        self::assertSame('{"ids":[1,2]}', StopwatchCheckpoint::formatMetadataValue(['ids' => [1, 2]]));

        $object = new stdClass();
        $object->url = 'https://example.test/a';
        self::assertSame('{"url":"https://example.test/a"}', StopwatchCheckpoint::formatMetadataValue($object));
    }

    public function test_scalars_and_stringables_render_unchanged(): void
    {
        self::assertSame('42', StopwatchCheckpoint::formatMetadataValue(42));
        self::assertSame('plain', StopwatchCheckpoint::formatMetadataValue('plain'));
        self::assertSame(str_repeat('x', 500), StopwatchCheckpoint::formatMetadataValue(str_repeat('x', 500), 200));
    }

    public function test_cap_cuts_json_and_appends_marker(): void
    {
        $formatted = StopwatchCheckpoint::formatMetadataValue(['blob' => str_repeat('a', 500)], 200);

        self::assertSame(201, mb_strlen($formatted));
        self::assertStringEndsWith('…', $formatted);
        self::assertStringStartsWith('{"blob":"aaa', $formatted);
    }

    public function test_invalid_utf8_keeps_the_other_keys(): void
    {
        $formatted = StopwatchCheckpoint::formatMetadataValue(['name' => "\xB1abc", 'ok' => 1]);

        self::assertSame("{\"name\":\"\u{FFFD}abc\",\"ok\":1}", $formatted);
    }

    public function test_recursion_resource_and_nan_become_partial_json(): void
    {
        $recursive = [1];
        $recursive[] = &$recursive;
        $resource = fopen('php://memory', 'rb');

        self::assertSame('[1,null]', StopwatchCheckpoint::formatMetadataValue($recursive));
        self::assertSame('{"handle":null,"ok":1}', StopwatchCheckpoint::formatMetadataValue(['handle' => $resource, 'ok' => 1]));
        self::assertSame('{"ratio":0}', StopwatchCheckpoint::formatMetadataValue(['ratio' => NAN]));
    }

    public function test_plain_text_line_shows_capped_json(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->checkpoint('Loaded', ['ids' => [1, 2], 'blob' => ['x' => str_repeat('b', 400)]]);

        $line = $stopwatch->lastCheckpointFormatted();

        self::assertStringContainsString('ids=[1,2]', $line);
        self::assertStringContainsString('blob={"x":"bbb', $line);
        self::assertStringContainsString('…', $line);
        self::assertStringNotContainsString('non-scalar value', $line);
    }

    public function test_markdown_metadata_cell_survives_invalid_utf8(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->checkpoint('bad', ['name' => "\xB1abc", 'ok' => 1]);

        self::assertStringContainsString('"ok":1', $stopwatch->toMarkdown());
    }

    public function test_html_card_shows_capped_json_for_non_scalar_metadata(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->checkpoint('Loaded', ['ids' => [7, 8], 'blob' => [str_repeat('c', 400)]]);

        $html = $stopwatch->toHtml();

        self::assertStringContainsString(e('[7,8]'), $html);
        self::assertStringNotContainsString(str_repeat('c', 250), $html);
        self::assertStringContainsString(e('["' . str_repeat('c', 198)) . '…', $html);
        self::assertStringNotContainsString('non-scalar value', $html);
    }

    public function test_debugbar_params_show_capped_json(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->checkpoint('Loaded', ['ids' => [7, 8], 'blob' => [str_repeat('d', 400)]]);
        $stopwatch->finish();

        $params = (new DebugbarCollector($stopwatch))->collect()['measures'][0]['params'];

        self::assertSame('[7,8]', $params['ids']);
        self::assertSame(201, mb_strlen((string) $params['blob']));
    }

    public function test_dump_output_dumps_the_metadata_array(): void
    {
        $dumped = [];
        $previous = VarDumper::setHandler(static function (mixed $var) use (&$dumped): void {
            $dumped[] = $var;
        });

        try {
            $stopwatch = Stopwatch::new(clock: new FakeClock());
            $stopwatch->checkpoint('With meta', ['ids' => [1, 2]], StopwatchOutput::Dump);
            $stopwatch->checkpoint('Without meta', null, StopwatchOutput::Dump);
        } finally {
            VarDumper::setHandler($previous);
        }

        self::assertCount(3, $dumped);
        self::assertIsString($dumped[0]);
        self::assertSame(['ids' => [1, 2]], $dumped[1]);
        self::assertIsString($dumped[2]);
        self::assertStringContainsString('Without meta', $dumped[2]);
    }

    public function test_query_bindings_are_captured_as_the_database_received_them(): void
    {
        stopwatch()->withQueryTracking()->start();

        DB::select('SELECT ?, ?, ?', [new DateTimeImmutable('2026-10-05 14:11:56'), false, true]);
        stopwatch()->checkpoint('After query');

        $bindings = stopwatch()->toArray()['checkpoints'][0]['queryCalls'][0]['bindings'];

        self::assertSame(['2026-10-05 14:11:56', 0, 1], $bindings);
    }

    public function test_run_log_sql_detail_keeps_binary_bindings(): void
    {
        $checkpoint = new StopwatchCheckpoint(
            label: 'Q',
            metadata: null,
            timeSinceLastCheckpointMs: 1.0,
            timeSinceStopwatchStartMs: 1.0,
            time: CarbonImmutable::now(),
            queryCount: 1,
            queryTimeMs: 1.0,
            queryCalls: [['sql' => 'SELECT ?, ?', 'bindings' => ["\xB1\x02uuid", 'next'], 'durationMs' => 1.0]],
        );

        $rendered = (new DetailRenderer(includeBindings: true))->render([$checkpoint]);

        self::assertStringContainsString('"next"', $rendered);
    }
}
