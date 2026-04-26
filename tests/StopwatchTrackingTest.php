<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests;

use Illuminate\Support\Facades\DB;
use SanderMuller\Stopwatch\FakeClock;
use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\StopwatchMiddleware;

final class StopwatchTrackingTest extends TestCase
{
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

        self::assertIsInt($data['checkpoints'][0]['memoryUsage']);
        self::assertIsInt($data['checkpoints'][0]['memoryDelta']);
        self::assertIsInt($data['checkpoints'][0]['memoryPeak']);

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

    public function test_middleware_sets_server_timing_header_when_started_by_user(): void
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

    public function test_middleware_auto_starts_when_configured(): void
    {
        $this->app->make('router')
            ->middleware(StopwatchMiddleware::autoStart())
            ->get('/test-autostart', static function (): string {
                stopwatch()->checkpoint('Controller');

                return 'ok';
            });

        $response = $this->get('/test-autostart');

        $response->assertOk();
        $header = $response->headers->get('Server-Timing');
        self::assertNotNull($header);
        self::assertStringContainsString('Controller;dur=', $header);
        self::assertStringContainsString('total;dur=', $header);
    }

    public function test_middleware_skips_header_when_not_started(): void
    {
        $this->app->make('router')
            ->middleware(StopwatchMiddleware::class)
            ->get('/test-not-started', static fn (): string => 'ok');

        $response = $this->get('/test-not-started');

        $response->assertOk();
        self::assertNull($response->headers->get('Server-Timing'));
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
}
