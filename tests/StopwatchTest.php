<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests;

use Carbon\CarbonInterval;
use SanderMuller\Stopwatch\FakeClock;
use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\StopwatchOutput;

final class StopwatchTest extends TestCase
{
    public function test_basic_start_checkpoint_and_to_string(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->start();
        $clock->advance(5);
        $stopwatch->checkpoint('First');
        $clock->advance(2);
        $stopwatch->checkpoint('Second');

        $str = $stopwatch->toString();
        self::assertIsString($str);
        self::assertStringEndsWith('ms', $str);

        $array = $stopwatch->toArray();
        self::assertArrayHasKey('startTime', $array);
        self::assertArrayHasKey('endTime', $array);
        self::assertArrayHasKey('checkpoints', $array);
        self::assertIsArray($array['checkpoints']);
        self::assertCount(2, $array['checkpoints']);
    }

    public function test_call_checkpoint_before_start_and_to_string(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $clock->advance(5);
        $stopwatch->checkpoint('First');
        $clock->advance(2);
        $stopwatch->checkpoint('Second');

        $str = $stopwatch->toString();
        self::assertIsString($str);
        self::assertStringEndsWith('ms', $str);

        $array = $stopwatch->toArray();
        self::assertArrayHasKey('startTime', $array);
        self::assertArrayHasKey('endTime', $array);
        self::assertArrayHasKey('checkpoints', $array);
        self::assertIsArray($array['checkpoints']);
        self::assertCount(2, $array['checkpoints']);
    }

    public function test_to_array_contains_flat_checkpoint_list(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->start();
        $stopwatch->checkpoint('A1');
        $stopwatch->checkpoint('A2');
        $stopwatch->checkpoint('B1');

        $data = $stopwatch->toArray();

        // Flat list
        self::assertArrayHasKey('checkpoints', $data);
        self::assertIsArray($data['checkpoints']);
        self::assertCount(3, $data['checkpoints']);
        self::assertArrayHasKey('timeSinceLastCheckpointMs', $data['checkpoints'][0]);
    }

    public function test_multiple_checkpoints_are_listed_correctly(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->start();
        $stopwatch->checkpoint('S1');
        $stopwatch->checkpoint('G1');

        $data = $stopwatch->toArray();

        self::assertCount(2, $data['checkpoints']);
    }

    public function test_time_since_last_checkpoint_updates_correctly(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->start();
        $carbonInterval = $stopwatch->timeSinceLastCheckpoint();
        self::assertInstanceOf(CarbonInterval::class, $carbonInterval);
        self::assertEquals(0, $carbonInterval->totalMilliseconds);

        $clock->advance(10);
        $stopwatch->checkpoint('After wait');
        $after = $stopwatch->timeSinceLastCheckpoint();
        self::assertInstanceOf(CarbonInterval::class, $after);
        self::assertEquals(10, $after->totalMilliseconds);

        $clock->advance(25);
        $stopwatch->checkpoint('Second');
        self::assertEquals(25, $stopwatch->timeSinceLastCheckpoint()->totalMilliseconds);
    }

    public function test_last_checkpoint_formatted_contains_brackets_and_label(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->start();
        $clock->advance(3);
        $stopwatch->checkpoint('Important');

        $formatted = $stopwatch->lastCheckpointFormatted();
        self::assertSame('[3ms / 3ms] Important', $formatted);
    }

    public function test_measure_wraps_closure_and_returns_result(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->start();

        $result = $stopwatch->measure('Computation', static fn (): int => 1 + 1);

        self::assertSame(2, $result);

        $data = $stopwatch->toArray();
        self::assertCount(1, $data['checkpoints']);
        self::assertSame('Computation', $data['checkpoints'][0]['label']);
    }

    public function test_measure_with_metadata(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->start();

        $stopwatch->measure('DB queries', static fn (): bool => true, ['queries' => 5]);

        $data = $stopwatch->toArray();
        self::assertSame(['queries' => 5], $data['checkpoints'][0]['metadata']);
    }

    public function test_measure_auto_starts_stopwatch(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());

        self::assertFalse($stopwatch->started());

        $stopwatch->measure('Auto start', static fn (): bool => true);

        self::assertTrue($stopwatch->started());
    }

    public function test_finish_does_not_add_synthetic_checkpoint(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->start();
        $stopwatch->checkpoint('Only');
        $stopwatch->finish();

        $data = $stopwatch->toArray();
        self::assertCount(1, $data['checkpoints']);
        self::assertSame('Only', $data['checkpoints'][0]['label']);
    }

    public function test_to_stderr_returns_self(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->start();
        $clock->advance(3);
        $stopwatch->checkpoint('Phase A');
        $clock->advance(5);
        $stopwatch->checkpoint('Phase B', ['queries' => 3]);

        $returnValue = $stopwatch->toStderr('Profile:');
        self::assertInstanceOf(Stopwatch::class, $returnValue);
    }

    public function test_output_defaults_to_silent(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->start();
        $stopwatch->checkpoint('Silent');

        $data = $stopwatch->toArray();
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
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->start();
        $clock->advance(7);
        $stopwatch->checkpoint('WithMeta', ['key' => 'val']);

        $formatted = $stopwatch->lastCheckpointFormatted();
        self::assertSame('[7ms / 7ms] WithMeta (key=val)', $formatted);
    }

    public function test_last_checkpoint_formatted_returns_empty_when_no_checkpoints(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->start();

        self::assertSame('', $stopwatch->lastCheckpointFormatted());
    }

    public function test_formatted_plain_text_handles_non_scalar_metadata(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->start();
        $stopwatch->checkpoint('NonScalar', ['nested' => ['a', 'b']]);

        $formatted = $stopwatch->lastCheckpointFormatted();
        self::assertStringContainsString('non-scalar value (array)', $formatted);
    }

    public function test_to_log_returns_self(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->start();
        $stopwatch->checkpoint('Phase A');
        $stopwatch->checkpoint('Phase B');

        $returnValue = $stopwatch->toLog('Profile:');
        self::assertInstanceOf(Stopwatch::class, $returnValue);
    }

    public function test_disabled_stopwatch_skips_checkpoints(): void
    {
        $stopwatch = Stopwatch::new();
        $stopwatch->disable();

        self::assertFalse($stopwatch->enabled());

        $stopwatch->start();
        $stopwatch->checkpoint('Should be ignored');
        $stopwatch->finish();

        self::assertFalse($stopwatch->started());
        self::assertFalse($stopwatch->ended());
        self::assertCount(0, $stopwatch->toArray()['checkpoints']);
    }

    public function test_disabled_measure_still_executes_callback(): void
    {
        $stopwatch = Stopwatch::new();
        $stopwatch->disable();

        $result = $stopwatch->measure('Ignored', static fn (): int => 42);

        self::assertSame(42, $result);
        self::assertCount(0, $stopwatch->toArray()['checkpoints']);
    }

    public function test_enable_after_disable_restores_functionality(): void
    {
        $stopwatch = Stopwatch::new();
        $stopwatch->disable();
        $stopwatch->start();
        $stopwatch->checkpoint('Ignored');

        $stopwatch->enable();
        $stopwatch->start();
        $stopwatch->checkpoint('Visible');

        self::assertCount(1, $stopwatch->toArray()['checkpoints']);
        self::assertSame('Visible', $stopwatch->toArray()['checkpoints'][0]['label']);
    }

    public function test_to_array_contains_precise_timing_values(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->start();
        $clock->advance(25);
        $stopwatch->checkpoint('First');
        $clock->advance(75);
        $stopwatch->checkpoint('Second');

        $data = $stopwatch->toArray();

        self::assertSame(25, $data['checkpoints'][0]['timeSinceLastCheckpointMs']);
        self::assertSame(25, $data['checkpoints'][0]['totalTimeElapsedMs']);
        self::assertSame(75, $data['checkpoints'][1]['timeSinceLastCheckpointMs']);
        self::assertSame(100, $data['checkpoints'][1]['totalTimeElapsedMs']);
        self::assertSame(100, $data['totalRunDurationMs']);
    }

    public function test_when_runs_callback_for_truthy_value_and_skips_for_falsy(): void
    {
        $stopwatch = Stopwatch::new();

        $truthyCalls = 0;
        $falsyCalls = 0;

        $result = $stopwatch
            ->when(true, function (Stopwatch $sw) use (&$truthyCalls, $stopwatch): void {
                $truthyCalls++;
                self::assertSame($stopwatch, $sw);
            })
            ->when(false, function () use (&$falsyCalls): void {
                $falsyCalls++;
            });

        self::assertSame(1, $truthyCalls);
        self::assertSame(0, $falsyCalls);
        self::assertSame($stopwatch, $result);
    }

    public function test_when_invokes_default_callback_for_falsy_value(): void
    {
        $stopwatch = Stopwatch::new();

        $primary = 0;
        $default = 0;

        $stopwatch->when(
            value: false,
            callback: function () use (&$primary): void {
                $primary++;
            },
            default: function () use (&$default): void {
                $default++;
            },
        );

        self::assertSame(0, $primary);
        self::assertSame(1, $default);
    }

    public function test_when_resolves_closure_value(): void
    {
        $stopwatch = Stopwatch::new();

        $calls = 0;
        $stopwatch->when(
            fn (Stopwatch $sw): bool => $sw->enabled(),
            function () use (&$calls): void {
                $calls++;
            },
        );

        self::assertSame(1, $calls);
    }

    public function test_unless_inverts_when(): void
    {
        $stopwatch = Stopwatch::new();

        $calls = 0;
        $stopwatch
            ->unless(false, function () use (&$calls): void {
                $calls++;
            })
            ->unless(true, function () use (&$calls): void {
                $calls++;
            });

        self::assertSame(1, $calls);
    }
}
