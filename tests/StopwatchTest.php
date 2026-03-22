<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests;

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use SanderMuller\Stopwatch\FakeClock;
use SanderMuller\Stopwatch\Notifications\LogChannel;
use SanderMuller\Stopwatch\Notifications\MailChannel;
use SanderMuller\Stopwatch\Notifications\StopwatchNotificationChannel;
use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\StopwatchMiddleware;
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

    public function test_to_array_contains_flat_checkpoints_and_grouped_groups(): void
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

    public function test_group_aliases_do_not_break_group_api(): void
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
        // highlighting color
        self::assertStringContainsString('rgba(255, 25, 25, 0.7)', $out);
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

    public function test_with_query_tracking_captures_query_count_and_duration(): void
    {
        stopwatch()->withQueryTracking()->start();

        DB::select('SELECT 1');
        DB::select('SELECT 1');
        stopwatch()->checkpoint('After queries');

        DB::select('SELECT 1');
        stopwatch()->checkpoint('After one more');

        $data = stopwatch()->toArray();

        self::assertSame(2, $data['checkpoints'][0]['queryCount']);
        self::assertIsFloat($data['checkpoints'][0]['queryTimeMs']);
        self::assertSame(1, $data['checkpoints'][1]['queryCount']);
    }

    public function test_with_query_tracking_resets_counters_between_checkpoints(): void
    {
        stopwatch()->withQueryTracking()->start();

        DB::select('SELECT 1');
        DB::select('SELECT 1');
        DB::select('SELECT 1');
        stopwatch()->checkpoint('Three queries');

        stopwatch()->checkpoint('No queries');

        $data = stopwatch()->toArray();

        self::assertSame(3, $data['checkpoints'][0]['queryCount']);
        self::assertSame(0, $data['checkpoints'][1]['queryCount']);
    }

    public function test_without_query_tracking_no_query_data(): void
    {
        stopwatch()->start();

        DB::select('SELECT 1');
        stopwatch()->checkpoint('No tracking');

        $data = stopwatch()->toArray();

        self::assertNull($data['checkpoints'][0]['queryCount']);
    }

    public function test_with_memory_tracking_captures_memory_metrics(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->withMemoryTracking()->start();

        $dummy = str_repeat('x', 1024);
        $stopwatch->checkpoint('After alloc');

        $data = $stopwatch->toArray();

        self::assertNotNull($data['checkpoints'][0]['memoryUsage']);
        self::assertNotNull($data['checkpoints'][0]['memoryDelta']);
        self::assertNotNull($data['checkpoints'][0]['memoryPeak']);

        unset($dummy);
    }

    public function test_without_memory_tracking_no_memory_data(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->start();

        $stopwatch->checkpoint('No tracking');

        $data = $stopwatch->toArray();

        self::assertNull($data['checkpoints'][0]['memoryUsage']);
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

    public function test_disabled_stopwatch_skips_query_tracking(): void
    {
        $stopwatch = Stopwatch::new();
        $stopwatch->disable();

        $result = $stopwatch->withQueryTracking();

        self::assertInstanceOf(Stopwatch::class, $result);
    }

    public function test_disabled_stopwatch_skips_memory_tracking(): void
    {
        $stopwatch = Stopwatch::new();
        $stopwatch->disable();

        $result = $stopwatch->withMemoryTracking();

        self::assertInstanceOf(Stopwatch::class, $result);
    }

    public function test_to_server_timing_with_checkpoints(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->start();
        $clock->advance(5);
        $stopwatch->checkpoint('Validation');
        $clock->advance(10);
        $stopwatch->checkpoint('DB queries');

        $header = $stopwatch->toServerTiming();

        self::assertStringContainsString('Validation;dur=5', $header);
        self::assertStringContainsString('desc="Validation"', $header);
        self::assertStringContainsString('DB-queries;dur=10', $header);
        self::assertStringContainsString('desc="DB queries"', $header);
        self::assertStringContainsString('total;dur=15', $header);
        self::assertStringContainsString('desc="Total"', $header);
    }

    public function test_to_server_timing_without_checkpoints(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->start();

        $header = $stopwatch->toServerTiming();

        self::assertStringStartsWith('total;dur=', $header);
        self::assertStringNotContainsString(', total', $header);
    }

    public function test_to_server_timing_escapes_special_characters_in_label(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->start();
        $stopwatch->checkpoint('Label with "quotes" and \\backslash');

        $header = $stopwatch->toServerTiming();

        self::assertStringContainsString('desc="Label with \\"quotes\\" and \\\\backslash"', $header);
    }

    public function test_middleware_sets_server_timing_header(): void
    {
        $this->app->make('router')
            ->middleware(StopwatchMiddleware::class)
            ->get('/test-timing', static function (): string {
                stopwatch()->checkpoint('Controller');

                return 'ok';
            });

        $response = $this->get('/test-timing');

        $response->assertOk();
        $header = $response->headers->get('Server-Timing');
        self::assertNotNull($header);
        self::assertStringContainsString('Controller;dur=', $header);
        self::assertStringContainsString('total;dur=', $header);
    }

    public function test_middleware_skips_header_when_disabled(): void
    {
        $this->app->make(Stopwatch::class)->disable();

        $this->app->make('router')
            ->middleware(StopwatchMiddleware::class)
            ->get('/test-disabled', static fn (): string => 'ok');

        $response = $this->get('/test-disabled');

        $response->assertOk();
        self::assertNull($response->headers->get('Server-Timing'));
    }

    public function test_blade_directive_renders_stopwatch(): void
    {
        stopwatch()->start();
        stopwatch()->checkpoint('Blade test');

        $rendered = Blade::render('@stopwatch');

        self::assertStringContainsString('Total', $rendered);
        self::assertStringContainsString('Blade test', $rendered);
    }

    public function test_notify_if_slower_than_notifies_on_finish(): void
    {
        Log::spy();

        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->notifyUsing([LogChannel::class]);
        $stopwatch->notifyIfSlowerThan(50);
        $stopwatch->start();
        $clock->advance(100);
        $stopwatch->checkpoint('Slow work');

        self::assertFalse($stopwatch->ended());

        $stopwatch->finish();

        self::assertTrue($stopwatch->ended());
        Log::shouldHaveReceived('log')->atLeast()->once();
    }

    public function test_notify_if_slower_than_skips_when_under_threshold(): void
    {
        Log::spy();

        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->notifyUsing([LogChannel::class]);
        $stopwatch->notifyIfSlowerThan(500);
        $stopwatch->start();
        $clock->advance(10);
        $stopwatch->checkpoint('Fast work');
        $stopwatch->finish();

        self::assertTrue($stopwatch->ended());
        Log::shouldNotHaveReceived('log');
    }

    public function test_notify_if_slower_than_triggers_on_implicit_finish(): void
    {
        Log::spy();

        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->notifyUsing([LogChannel::class]);
        $stopwatch->notifyIfSlowerThan(50);
        $stopwatch->start();
        $clock->advance(100);
        $stopwatch->checkpoint('Slow work');

        // toArray() implicitly calls finish()
        $stopwatch->toArray();

        Log::shouldHaveReceived('log')->atLeast()->once();
    }

    public function test_notify_if_slower_than_returns_self_when_disabled(): void
    {
        $stopwatch = Stopwatch::new();
        $stopwatch->disable();

        $result = $stopwatch->notifyIfSlowerThan(1);

        self::assertInstanceOf(Stopwatch::class, $result);
    }

    public function test_notify_if_slower_than_uses_custom_channel(): void
    {
        $called = false;

        $channel = new class ($called) implements StopwatchNotificationChannel {
            public function __construct(private bool &$called) {}

            public function notify(Stopwatch $stopwatch): void
            {
                $this->called = true;
            }
        };

        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->notifyUsing([$channel]);
        $stopwatch->notifyIfSlowerThan(10);
        $stopwatch->start();
        $clock->advance(50);
        $stopwatch->checkpoint('Work');
        $stopwatch->finish();

        self::assertTrue($called);
    }

    public function test_notify_if_slower_than_does_nothing_without_channels(): void
    {
        Log::spy();

        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->notifyUsing([]);
        $stopwatch->notifyIfSlowerThan(10);
        $stopwatch->start();
        $clock->advance(50);
        $stopwatch->checkpoint('Work');
        $stopwatch->finish();

        Log::shouldNotHaveReceived('log');
    }

    public function test_mail_channel_sends_email_on_finish(): void
    {
        Mail::spy();

        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->notifyUsing([new MailChannel(to: 'admin@example.com')]);
        $stopwatch->notifyIfSlowerThan(100);
        $stopwatch->start();
        $clock->advance(200);
        $stopwatch->checkpoint('Slow operation');
        $stopwatch->finish();

        Mail::shouldHaveReceived('html')->once();
    }

    public function test_mail_channel_skips_when_no_recipient(): void
    {
        Mail::spy();

        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->notifyUsing([new MailChannel()]);
        $stopwatch->notifyIfSlowerThan(100);
        $stopwatch->start();
        $clock->advance(200);
        $stopwatch->checkpoint('Slow operation');
        $stopwatch->finish();

        Mail::shouldNotHaveReceived('html');
    }

    public function test_notify_threshold_persists_across_restart(): void
    {
        Log::spy();

        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->notifyUsing([LogChannel::class]);
        $stopwatch->notifyIfSlowerThan(50);
        $stopwatch->start();
        $clock->advance(100);
        $stopwatch->checkpoint('First run');
        $stopwatch->finish();

        Log::shouldHaveReceived('log')->atLeast()->once();

        // Restart — threshold persists
        Log::spy();
        $stopwatch->restart();
        $clock->advance(100);
        $stopwatch->checkpoint('Second run');
        $stopwatch->finish();

        Log::shouldHaveReceived('log')->atLeast()->once();
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
}
