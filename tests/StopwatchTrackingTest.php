<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests;

use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    public function test_with_query_tracking_captures_per_query_sql_and_bindings(): void
    {
        stopwatch()->withQueryTracking()->start();

        DB::select('SELECT ?', [42]);
        DB::select('SELECT ?', ['foo']);
        stopwatch()->checkpoint('After two queries');

        $data = stopwatch()->toArray();
        $calls = $data['checkpoints'][0]['queryCalls'];

        self::assertCount(2, $calls);
        self::assertSame('SELECT ?', $calls[0]['sql']);
        self::assertSame([42], $calls[0]['bindings']);
        self::assertSame(['foo'], $calls[1]['bindings']);
        self::assertIsFloat($calls[0]['durationMs']);
    }

    public function test_query_tracking_caps_stored_query_details_at_50(): void
    {
        stopwatch()->withQueryTracking()->start();

        for ($i = 0; $i < 65; $i++) {
            DB::select('SELECT 1');
        }

        stopwatch()->checkpoint('Many queries');

        $data = stopwatch()->toArray();

        self::assertSame(65, $data['checkpoints'][0]['queryCount']);
        self::assertCount(50, $data['checkpoints'][0]['queryCalls']);
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

    public function test_re_enabling_query_tracking_resets_pending_query_details(): void
    {
        // Reproduces a regression where calling withQueryTracking() a second time mid-run
        // reset queryCount/queryDurationMs but left stale entries in queryCalls, causing the
        // next checkpoint's expansion panel to show SQL from before the second enablement.
        stopwatch()->withQueryTracking()->start();

        DB::select('SELECT 1');
        DB::select('SELECT 2');

        // Re-enable mid-flight before the next checkpoint flushes.
        stopwatch()->withQueryTracking();

        DB::select('SELECT 3');
        stopwatch()->checkpoint('After re-enable');

        $data = stopwatch()->toArray();
        $calls = $data['checkpoints'][0]['queryCalls'];

        // Only the post-re-enable query should be visible — the count + the calls list must agree.
        self::assertSame(1, $data['checkpoints'][0]['queryCount']);
        self::assertCount(1, $calls);
        self::assertSame('SELECT 3', $calls[0]['sql']);
    }

    public function test_http_tracking_preserves_in_flight_request_starts_across_checkpoints(): void
    {
        // Simulates a pool/async request whose ResponseReceived arrives in a later checkpoint
        // than its RequestSending. The duration must come from the pre-checkpoint start
        // hrtime, not be lost when we flushed the previous checkpoint.
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock)->withHttpTracking()->start();

        $request = new \Illuminate\Http\Client\Request(
            new Request('GET', 'https://api.example.com/long-poll'),
        );
        event(new RequestSending($request));

        $clock->advance(50);
        $stopwatch->checkpoint('Mid-flight');

        $clock->advance(80);
        event(new ConnectionFailed($request, new ConnectionException('still pending')));

        $stopwatch->checkpoint('After response');

        $data = $stopwatch->toArray();

        self::assertSame(0, $data['checkpoints'][0]['httpCount'], 'No completed call yet at first checkpoint');
        self::assertSame(1, $data['checkpoints'][1]['httpCount']);
        self::assertSame(130.0, $data['checkpoints'][1]['httpCalls'][0]['durationMs'], 'Total elapsed = 50 + 80 = 130ms');
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

    public function test_with_http_tracking_captures_call_count_and_summary(): void
    {
        Http::fake([
            'api.example.com/users' => Http::response(['ok' => true], 200),
            'api.example.com/orders' => Http::response(['ok' => true], 201),
            'api.example.com/items' => Http::response('boom', 500),
        ]);

        stopwatch()->withHttpTracking()->start();

        Http::get('https://api.example.com/users');
        Http::post('https://api.example.com/orders', ['x' => 1]);
        stopwatch()->checkpoint('After two calls');

        Http::get('https://api.example.com/items');
        stopwatch()->checkpoint('After third call');

        $data = stopwatch()->toArray();

        self::assertSame(2, $data['checkpoints'][0]['httpCount']);
        self::assertCount(2, $data['checkpoints'][0]['httpCalls']);
        self::assertSame('GET', $data['checkpoints'][0]['httpCalls'][0]['method']);
        self::assertSame(200, $data['checkpoints'][0]['httpCalls'][0]['status']);
        self::assertSame(1, $data['checkpoints'][1]['httpCount']);
        self::assertSame(500, $data['checkpoints'][1]['httpCalls'][0]['status']);
    }

    public function test_with_http_tracking_resets_counters_between_checkpoints(): void
    {
        Http::fake(['api.example.com/*' => Http::response('ok', 200)]);

        stopwatch()->withHttpTracking()->start();

        Http::get('https://api.example.com/a');
        Http::get('https://api.example.com/b');
        Http::get('https://api.example.com/c');
        stopwatch()->checkpoint('Three calls');

        stopwatch()->checkpoint('No calls');

        $data = stopwatch()->toArray();

        self::assertSame(3, $data['checkpoints'][0]['httpCount']);
        self::assertSame(0, $data['checkpoints'][1]['httpCount']);
    }

    public function test_without_http_tracking_no_http_data(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        stopwatch()->start();

        Http::get('https://api.example.com/a');
        stopwatch()->checkpoint('No tracking');

        $data = stopwatch()->toArray();

        self::assertNull($data['checkpoints'][0]['httpCount']);
    }

    public function test_http_tracking_caps_stored_call_details_at_50(): void
    {
        Http::fake(['api.example.com/*' => Http::response('ok', 200)]);

        stopwatch()->withHttpTracking()->start();

        for ($i = 0; $i < 65; $i++) {
            Http::get("https://api.example.com/item-{$i}");
        }

        stopwatch()->checkpoint('Many calls');

        $data = stopwatch()->toArray();

        self::assertSame(65, $data['checkpoints'][0]['httpCount']);
        self::assertCount(50, $data['checkpoints'][0]['httpCalls']);
    }

    public function test_http_tracking_handles_connection_failure_event(): void
    {
        stopwatch()->withHttpTracking()->start();

        // Dispatching ConnectionFailed directly is more reliable than Http::fake() throw closures,
        // which take different paths depending on Laravel version and don't always reach the event.
        $request = new \Illuminate\Http\Client\Request(
            new Request('GET', 'https://api.example.com/down'),
        );
        event(new ConnectionFailed(
            $request,
            new ConnectionException('boom'),
        ));

        stopwatch()->checkpoint('After failure');

        $data = stopwatch()->toArray();

        self::assertSame(1, $data['checkpoints'][0]['httpCount']);
        self::assertSame(0, $data['checkpoints'][0]['httpCalls'][0]['status']);
    }

    public function test_http_tracking_captures_elapsed_time_for_failed_requests(): void
    {
        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock)->withHttpTracking()->start();

        // Simulate the RequestSending → ConnectionFailed lifecycle for the same Request instance.
        // ConnectionFailed carries no transferStats, so the duration must be recovered from the
        // RequestSending start hrtime — otherwise a 30s timeout looks like 0ms.
        $request = new \Illuminate\Http\Client\Request(
            new Request('GET', 'https://api.example.com/timeout'),
        );
        event(new RequestSending($request));
        $clock->advance(120);
        event(new ConnectionFailed($request, new ConnectionException('timeout')));

        $stopwatch->checkpoint('After timeout');

        $data = $stopwatch->toArray();

        self::assertSame(120.0, $data['checkpoints'][0]['httpCalls'][0]['durationMs']);
    }

    public function test_http_tracking_strips_query_string_from_stored_url(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        stopwatch()->withHttpTracking()->start();

        Http::get('https://api.example.com/users?token=secret&page=2');
        stopwatch()->checkpoint('After call');

        $data = stopwatch()->toArray();
        $url = $data['checkpoints'][0]['httpCalls'][0]['url'];

        self::assertSame('https://api.example.com/users', $url);
        self::assertStringNotContainsString('token=secret', $url);
    }

    public function test_disabled_stopwatch_skips_http_tracking(): void
    {
        $stopwatch = Stopwatch::new();
        $stopwatch->disable();

        $result = $stopwatch->withHttpTracking();

        self::assertInstanceOf(Stopwatch::class, $result);
    }

    public function test_with_http_tracking_listener_is_idempotent(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        // Calling withHttpTracking() multiple times should not register the listener
        // multiple times, otherwise each Http call would be counted N times.
        stopwatch()->withHttpTracking()->withHttpTracking()->withHttpTracking()->start();

        Http::get('https://api.example.com/once');
        stopwatch()->checkpoint('After one call');

        $data = stopwatch()->toArray();

        self::assertSame(1, $data['checkpoints'][0]['httpCount']);
    }

    public function test_query_memory_and_http_tracking_combine_without_conflict(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $stopwatch = stopwatch()->withQueryTracking()->withMemoryTracking()->withHttpTracking();
        $stopwatch->start();

        DB::select('SELECT 1');
        Http::get('https://api.example.com/a');
        $dummy = str_repeat('x', 1024);
        $stopwatch->checkpoint('All three');

        $data = $stopwatch->toArray();

        self::assertSame(1, $data['checkpoints'][0]['queryCount']);
        self::assertSame(1, $data['checkpoints'][0]['httpCount']);
        self::assertIsInt($data['checkpoints'][0]['memoryUsage']);

        unset($dummy);
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
