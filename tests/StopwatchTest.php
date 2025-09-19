<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests;

use Carbon\CarbonInterval;
use Illuminate\Support\HtmlString;
use SanderMuller\Stopwatch\Stopwatch;

final class StopwatchTest extends TestCase
{
    public function test_basic_start_checkpoint_and_to_string(): void
    {
        stopwatch()->start();
        usleep(5000); // 5ms
        stopwatch()->checkpoint('First');
        usleep(2000); // 2ms
        stopwatch()->checkpoint('Second');

        $str = stopwatch()->toString();
        self::assertIsString($str);
        self::assertStringEndsWith('ms', $str);

        $array = stopwatch()->toArray();
        self::assertArrayHasKey('startTime', $array);
        self::assertArrayHasKey('endTime', $array);
        self::assertArrayHasKey('checkpoints', $array);
        self::assertIsArray($array['checkpoints']);
        self::assertCount(3, $array['checkpoints']);
    }

    public function test_to_array_contains_flat_checkpoints_and_grouped_groups(): void
    {
        stopwatch()->start();
        stopwatch()->checkpoint('A1');
        stopwatch()->checkpoint('A2');
        stopwatch()->checkpoint('B1');

        $data = stopwatch()->toArray();

        // Flat list
        self::assertArrayHasKey('checkpoints', $data);
        self::assertIsArray($data['checkpoints']);
        self::assertCount(4, $data['checkpoints']);
        self::assertArrayHasKey('timeSinceLastCheckpointMs', $data['checkpoints'][0]);
    }

    public function test_group_aliases_do_not_break_group_api(): void
    {
        stopwatch()->start();
        stopwatch()->checkpoint('S1');
        stopwatch()->checkpoint('G1');

        $data = stopwatch()->toArray();

        self::assertCount(3, $data['checkpoints']);
    }

    public function test_time_since_last_checkpoint_updates_correctly(): void
    {
        stopwatch()->start();
        $carbonInterval = stopwatch()->timeSinceLastCheckpoint();
        self::assertInstanceOf(CarbonInterval::class, $carbonInterval);
        self::assertGreaterThanOrEqual(0, $carbonInterval->totalMilliseconds);

        usleep(2000); // wait 2ms
        stopwatch()->checkpoint('After wait');
        $after = stopwatch()->timeSinceLastCheckpoint();
        self::assertInstanceOf(CarbonInterval::class, $after);
        self::assertGreaterThanOrEqual(0, $after->totalMilliseconds);

        usleep(2000);
        $later = stopwatch()->timeSinceLastCheckpoint();
        self::assertGreaterThanOrEqual(1, round($later->totalMilliseconds));
    }

    public function test_render_html_contains_total_and_group_label_and_highlighting(): void
    {
        stopwatch()->start();
        stopwatch()->checkpoint('C1');
        stopwatch()->slowCheckpointThreshold(0); // mark all as slow for deterministic highlighting
        usleep(600);
        stopwatch()->checkpoint('C2');

        $htmlString = stopwatch()->render();
        self::assertInstanceOf(HtmlString::class, $htmlString);
        $out = (string) $htmlString;
        self::assertStringContainsString('Total', $out);
        // highlighting color
        self::assertStringContainsString('rgba(255, 25, 25, 0.7)', $out);
    }

    public function test_last_checkpoint_formatted_contains_brackets_and_label(): void
    {
        stopwatch()->start();
        stopwatch()->checkpoint('Important');

        $formatted = app(Stopwatch::class)->lastCheckpointFormatted();
        self::assertMatchesRegularExpression('/\[\d+ms \/ \d+ms] Important/', $formatted);
    }
}
