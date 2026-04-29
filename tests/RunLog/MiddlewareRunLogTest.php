<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\RunLog;

use RuntimeException;
use SanderMuller\Stopwatch\RunLog\RunRecorder;
use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\StopwatchMiddleware;
use SanderMuller\Stopwatch\Tests\TestCase;

final class MiddlewareRunLogTest extends TestCase
{
    public function test_middleware_populates_url_method_status_in_run_context(): void
    {
        $captured = $this->captureContextOnFinish();

        $this->app->make('router')
            ->middleware(StopwatchMiddleware::autoStart())
            ->get('/profile-target', static function (): string {
                stopwatch()->checkpoint('Step');

                return 'ok';
            });

        $response = $this->get('/profile-target?token=secret');

        $response->assertOk();
        self::assertNotNull($captured());
        self::assertSame('GET', $captured()['method']);
        self::assertSame(200, $captured()['status']);
        self::assertStringEndsWith('/profile-target', (string) $captured()['url']);
        self::assertStringNotContainsString('token=secret', (string) $captured()['url']);
        self::assertArrayNotHasKey('threw', $captured());
    }

    public function test_middleware_writes_run_log_on_exception_and_re_throws(): void
    {
        $captured = $this->captureContextOnFinish();

        $this->app->make('router')
            ->middleware(StopwatchMiddleware::autoStart())
            ->get('/profile-explode', static function (): string {
                stopwatch()->checkpoint('Before crash');

                throw new RuntimeException('controller boom');
            });

        $threw = false;

        try {
            $this->withoutExceptionHandling()->get('/profile-explode');
        } catch (RuntimeException $e) {
            $threw = true;
            self::assertSame('controller boom', $e->getMessage());
        }

        self::assertTrue($threw, 'middleware must let the controller exception propagate');
        self::assertNotNull($captured());
        self::assertTrue($captured()['threw']);
        self::assertSame(500, $captured()['status']);
    }

    public function test_middleware_skips_finish_when_stopwatch_disabled(): void
    {
        $captured = $this->captureContextOnFinish();

        $this->app->make(Stopwatch::class)->disable();

        $this->app->make('router')
            ->middleware(StopwatchMiddleware::class)
            ->get('/profile-disabled', static fn (): string => 'ok');

        $this->get('/profile-disabled')->assertOk();

        self::assertNull($captured());
    }

    /**
     * Wires a recorder onto the singleton stopwatch and returns a closure that
     * yields the most recently captured context (or null if no record happened).
     *
     * @return callable(): ?array<string, scalar|null>
     */
    private function captureContextOnFinish(): callable
    {
        $captured = null;

        $recorder = new class ($captured) implements RunRecorder {
            /** @param array<string, scalar|null>|null $captured */
            public function __construct(public ?array &$captured) {}

            public function record(Stopwatch $stopwatch, array $context): void
            {
                $this->captured = $context;
            }
        };

        $this->app->make(Stopwatch::class)->recordRunsTo($recorder);

        return static fn (): ?array => $recorder->captured;
    }
}
