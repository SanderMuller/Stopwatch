<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests;

use SanderMuller\Stopwatch\FakeClock;
use SanderMuller\Stopwatch\Integrations\DebugbarCollector;
use SanderMuller\Stopwatch\Stopwatch;

final class DebugbarCollectorTest extends TestCase
{
    public function test_collect_returns_timeline_format(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->start();
        $clock->advance(10);
        $stopwatch->checkpoint('First');
        $clock->advance(30);
        $stopwatch->checkpoint('Second');

        $collector = new DebugbarCollector($stopwatch);
        $data = $collector->collect();

        self::assertArrayHasKey('start', $data);
        self::assertArrayHasKey('end', $data);
        self::assertArrayHasKey('duration', $data);
        self::assertArrayHasKey('duration_str', $data);
        self::assertArrayHasKey('measures', $data);

        self::assertSame('40ms', $data['duration_str']);
        self::assertSame(0.04, $data['duration']);
        self::assertCount(2, $data['measures']);
    }

    public function test_collect_measures_have_correct_timing(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->start();
        $clock->advance(10);
        $stopwatch->checkpoint('First');
        $clock->advance(30);
        $stopwatch->checkpoint('Second');

        $collector = new DebugbarCollector($stopwatch);
        $data = $collector->collect();

        $first = $data['measures'][0];
        self::assertSame('First', $first['label']);
        self::assertEquals(0, $first['relative_start']);
        self::assertEquals(0.01, $first['relative_end']);
        self::assertEquals(0.01, $first['duration']);
        self::assertSame('10ms', $first['duration_str']);

        $second = $data['measures'][1];
        self::assertSame('Second', $second['label']);
        self::assertEquals(0.01, $second['relative_start']);
        self::assertEquals(0.04, $second['relative_end']);
        self::assertEquals(0.03, $second['duration']);
        self::assertSame('30ms', $second['duration_str']);
    }

    public function test_collect_uses_stopwatch_start_time_for_anchoring(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->start();
        $clock->advance(10);
        $stopwatch->checkpoint('First');

        $collector = new DebugbarCollector($stopwatch);
        $data = $collector->collect();

        $expectedStart = (float) $stopwatch->startTime()->format('U.u');
        self::assertSame($expectedStart, $data['start']);
        self::assertSame($expectedStart, $data['measures'][0]['start']);
    }

    public function test_collect_includes_query_metrics_in_params(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->start();
        $stopwatch->checkpoint('With queries', metadata: ['key' => 'val']);

        $collector = new DebugbarCollector($stopwatch);
        $data = $collector->collect();

        self::assertSame('val', $data['measures'][0]['params']['key']);
    }

    public function test_collect_handles_empty_stopwatch(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());

        $collector = new DebugbarCollector($stopwatch);
        $data = $collector->collect();

        self::assertSame([], $data['measures']);
        self::assertSame('0ms', $data['duration_str']);
    }

    public function test_get_name_returns_stopwatch(): void
    {
        $collector = new DebugbarCollector(Stopwatch::new());

        self::assertSame('stopwatch', $collector->getName());
    }

    public function test_get_widgets_returns_timeline_and_badge(): void
    {
        $collector = new DebugbarCollector(Stopwatch::new());
        $widgets = $collector->getWidgets();

        self::assertArrayHasKey('stopwatch', $widgets);
        self::assertSame('PhpDebugBar.Widgets.TimelineWidget', $widgets['stopwatch']['widget']);

        self::assertArrayHasKey('stopwatch:badge', $widgets);
        self::assertSame('stopwatch.duration_str', $widgets['stopwatch:badge']['map']);
    }
}
