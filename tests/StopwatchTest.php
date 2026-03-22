<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests;

use Carbon\CarbonInterval;
use Illuminate\Support\HtmlString;
use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\StopwatchOutput;

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
        self::assertCount(2, $array['checkpoints']);
    }

    public function test_call_checkpoint_before_start_and_to_string(): void
    {
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
        self::assertCount(2, $array['checkpoints']);
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
        self::assertCount(3, $data['checkpoints']);
        self::assertArrayHasKey('timeSinceLastCheckpointMs', $data['checkpoints'][0]);
    }

    public function test_group_aliases_do_not_break_group_api(): void
    {
        stopwatch()->start();
        stopwatch()->checkpoint('S1');
        stopwatch()->checkpoint('G1');

        $data = stopwatch()->toArray();

        self::assertCount(2, $data['checkpoints']);
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

    public function test_measure_wraps_closure_and_returns_result(): void
    {
        stopwatch()->start();

        $result = stopwatch()->measure('Computation', static fn (): int => 1 + 1);

        self::assertSame(2, $result);

        $data = stopwatch()->toArray();
        self::assertCount(1, $data['checkpoints']);
        self::assertSame('Computation', $data['checkpoints'][0]['label']);
    }

    public function test_measure_with_metadata(): void
    {
        stopwatch()->start();

        stopwatch()->measure('DB queries', static fn (): bool => true, ['queries' => 5]);

        $data = stopwatch()->toArray();
        self::assertSame(['queries' => 5], $data['checkpoints'][0]['metadata']);
    }

    public function test_measure_auto_starts_stopwatch(): void
    {
        self::assertFalse(stopwatch()->started());

        stopwatch()->measure('Auto start', static fn (): bool => true);

        self::assertTrue(stopwatch()->started());
    }

    public function test_finish_does_not_add_synthetic_checkpoint(): void
    {
        stopwatch()->start();
        stopwatch()->checkpoint('Only');
        stopwatch()->finish();

        $data = stopwatch()->toArray();
        self::assertCount(1, $data['checkpoints']);
        self::assertSame('Only', $data['checkpoints'][0]['label']);
    }

    public function test_to_stderr_returns_self(): void
    {
        stopwatch()->start();
        usleep(1000);
        stopwatch()->checkpoint('Phase A');
        usleep(1000);
        stopwatch()->checkpoint('Phase B', ['queries' => 3]);

        $returnValue = stopwatch()->toStderr('Profile:');
        self::assertInstanceOf(Stopwatch::class, $returnValue);
    }

    public function test_output_defaults_to_silent(): void
    {
        stopwatch()->start();
        stopwatch()->checkpoint('Silent');

        $data = stopwatch()->toArray();
        self::assertCount(1, $data['checkpoints']);
        self::assertSame('Silent', $data['checkpoints'][0]['label']);
    }

    public function test_output_to_returns_self(): void
    {
        $stopwatch = Stopwatch::new();
        $result = $stopwatch->outputTo(StopwatchOutput::Stderr);

        self::assertInstanceOf(Stopwatch::class, $result);
    }

    public function test_last_checkpoint_formatted_includes_metadata(): void
    {
        stopwatch()->start();
        stopwatch()->checkpoint('WithMeta', ['key' => 'val']);

        $formatted = stopwatch()->lastCheckpointFormatted();
        self::assertMatchesRegularExpression('/\[\d+ms \/ \d+ms] WithMeta/', $formatted);
        self::assertStringContainsString('key=val', $formatted);
    }

    public function test_last_checkpoint_formatted_returns_empty_when_no_checkpoints(): void
    {
        stopwatch()->start();

        self::assertSame('', stopwatch()->lastCheckpointFormatted());
    }

    public function test_formatted_plain_text_handles_non_scalar_metadata(): void
    {
        stopwatch()->start();
        stopwatch()->checkpoint('NonScalar', ['nested' => ['a', 'b']]);

        $formatted = stopwatch()->lastCheckpointFormatted();
        self::assertStringContainsString('non-scalar value (array)', $formatted);
    }

    public function test_to_log_returns_self(): void
    {
        stopwatch()->start();
        stopwatch()->checkpoint('Phase A');
        stopwatch()->checkpoint('Phase B');

        $returnValue = stopwatch()->toLog('Profile:');
        self::assertInstanceOf(Stopwatch::class, $returnValue);
    }
}
