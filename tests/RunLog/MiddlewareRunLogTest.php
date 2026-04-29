<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\RunLog;

use RuntimeException;
use SanderMuller\Stopwatch\RunLog\ContextCapture;
use SanderMuller\Stopwatch\RunLog\ContextCaptureRenderer;
use SanderMuller\Stopwatch\RunLog\ExceptionDetail;
use SanderMuller\Stopwatch\RunLog\ExceptionDetailRenderer;
use SanderMuller\Stopwatch\RunLog\MarkdownRunRecorder;
use SanderMuller\Stopwatch\RunLog\RunLogStore;
use SanderMuller\Stopwatch\RunLog\RunRecorder;
use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\StopwatchMiddleware;
use SanderMuller\Stopwatch\Tests\TestCase;
use Throwable;

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

    public function test_middleware_sets_transient_exception_context_before_recorder_dispatch(): void
    {
        $capturedException = null;
        $recorder = new class ($capturedException) implements RunRecorder {
            public function __construct(public ?Throwable &$captured) {}

            public function record(Stopwatch $stopwatch, array $context): void
            {
                $value = $stopwatch->transientContext(Stopwatch::TRANSIENT_EXCEPTION);
                $this->captured = $value instanceof Throwable ? $value : null;
            }
        };

        $this->app->make(Stopwatch::class)->recordRunsTo($recorder);

        $this->app->make('router')
            ->middleware(StopwatchMiddleware::autoStart())
            ->get('/profile-throws-transient', static function (): string {
                stopwatch()->checkpoint('Before crash');

                throw new RuntimeException('controller boom');
            });

        try {
            $this->withoutExceptionHandling()->get('/profile-throws-transient');
        } catch (RuntimeException) {
            // expected
        }

        self::assertInstanceOf(RuntimeException::class, $recorder->captured);
        self::assertSame('controller boom', $recorder->captured->getMessage());
    }

    public function test_middleware_run_log_persists_exception_class_to_disk(): void
    {
        $tempDir = sys_get_temp_dir() . '/stopwatch-mw-ec-' . bin2hex(random_bytes(6));

        try {
            $store = new RunLogStore($tempDir);
            $recorder = new MarkdownRunRecorder(
                store: $store,
                minDurationMs: null,
                skipEmpty: false,
                collectExceptions: true,
                exceptionRenderer: new ExceptionDetailRenderer(new ExceptionDetail(messageEnabled: true)),
                collectContext: false,
                contextCapture: new ContextCapture(),
                contextRenderer: new ContextCaptureRenderer(),
            );

            $this->app->make(Stopwatch::class)->recordRunsTo($recorder);

            $this->app->make('router')
                ->middleware(StopwatchMiddleware::autoStart())
                ->get('/profile-disk-crash', static function (): string {
                    stopwatch()->checkpoint('Before crash');

                    throw new RuntimeException('disk-bound boom');
                });

            try {
                $this->withoutExceptionHandling()->get('/profile-disk-crash');
            } catch (RuntimeException) {
                // expected
            }

            $files = glob($tempDir . '/*.md') ?: [];
            self::assertCount(1, $files);

            $contents = (string) file_get_contents($files[0]);
            self::assertStringContainsString('exception_class: RuntimeException', $contents);
            self::assertStringContainsString('exception_line:', $contents);
            self::assertStringContainsString('threw: true', $contents);
            self::assertStringContainsString('## Exception', $contents);
            self::assertStringContainsString('- **Message:** disk-bound boom', $contents);
            // No stringified Throwable object internals — just the persistable subset.
            self::assertStringNotContainsString('Object#', $contents);
            self::assertStringNotContainsString('protected:', $contents);
            self::assertStringNotContainsString('#trace', $contents);
        } finally {
            $this->cleanupTempDir($tempDir);
        }
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

    private function cleanupTempDir(string $tempDir): void
    {
        if (! is_dir($tempDir)) {
            return;
        }

        foreach (glob($tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @unlink($tempDir . '/.gitignore');
        @rmdir($tempDir);
    }
}
