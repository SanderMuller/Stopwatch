<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\RunLog;

use RuntimeException;
use SanderMuller\Stopwatch\FakeClock;
use SanderMuller\Stopwatch\RunLog\RunRecorder;
use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\Tests\TestCase;
use stdClass;

final class StopwatchTransientContextTest extends TestCase
{
    public function test_set_and_get_round_trip(): void
    {
        $exception = new RuntimeException('boom');
        $stopwatch = $this->makeStopwatch();

        $stopwatch->withTransientContext(Stopwatch::TRANSIENT_EXCEPTION, $exception);

        self::assertSame($exception, $stopwatch->transientContext(Stopwatch::TRANSIENT_EXCEPTION));
    }

    public function test_missing_key_returns_null(): void
    {
        $stopwatch = $this->makeStopwatch();

        self::assertNull($stopwatch->transientContext('not-set'));
    }

    public function test_supports_mixed_value_types(): void
    {
        $object = new stdClass();
        $object->id = 42;

        $stopwatch = $this->makeStopwatch();
        $stopwatch
            ->withTransientContext('object', $object)
            ->withTransientContext('array', ['nested' => true])
            ->withTransientContext('scalar', 'plain string');

        self::assertSame($object, $stopwatch->transientContext('object'));
        self::assertSame(['nested' => true], $stopwatch->transientContext('array'));
        self::assertSame('plain string', $stopwatch->transientContext('scalar'));
    }

    public function test_cleared_on_reset(): void
    {
        $stopwatch = $this->makeStopwatch();
        $stopwatch->withTransientContext(Stopwatch::TRANSIENT_EXCEPTION, new RuntimeException('first'));

        $stopwatch->start();

        self::assertNull($stopwatch->transientContext(Stopwatch::TRANSIENT_EXCEPTION));
    }

    public function test_cleared_on_restart_when_first_run_abandoned(): void
    {
        $stopwatch = $this->makeStopwatch();
        $stopwatch->start();
        $stopwatch->withTransientContext(Stopwatch::TRANSIENT_EXCEPTION, new RuntimeException('abandoned'));

        $stopwatch->restart();

        self::assertNull($stopwatch->transientContext(Stopwatch::TRANSIENT_EXCEPTION));
    }

    public function test_survives_between_checkpoint_and_finish_then_clears_after_finish(): void
    {
        $captured = null;
        $stopwatch = $this->makeStopwatch();
        $stopwatch->recordRunsTo($this->captureRecorder(function (Stopwatch $sw) use (&$captured): void {
            $captured = $sw->transientContext(Stopwatch::TRANSIENT_EXCEPTION);
        }));

        $stopwatch->start();
        $stopwatch->checkpoint('Before exception');
        $exception = new RuntimeException('mid-run');
        $stopwatch->withTransientContext(Stopwatch::TRANSIENT_EXCEPTION, $exception);
        $stopwatch->checkpoint('After exception');
        $stopwatch->finish();

        // Recorder saw the exception during dispatch.
        self::assertSame($exception, $captured);

        // Post-dispatch clear: a fresh getter call returns null.
        self::assertNull($stopwatch->transientContext(Stopwatch::TRANSIENT_EXCEPTION));
    }

    public function test_multiple_keys_coexist(): void
    {
        $stopwatch = $this->makeStopwatch();
        $stopwatch->withTransientContext('a', 1);
        $stopwatch->withTransientContext('b', 2);
        $stopwatch->withTransientContext('c', 3);

        self::assertSame(1, $stopwatch->transientContext('a'));
        self::assertSame(2, $stopwatch->transientContext('b'));
        self::assertSame(3, $stopwatch->transientContext('c'));
    }

    public function test_overwrite_replaces_value(): void
    {
        $stopwatch = $this->makeStopwatch();
        $stopwatch->withTransientContext('k', 'first');
        $stopwatch->withTransientContext('k', 'second');

        self::assertSame('second', $stopwatch->transientContext('k'));
    }

    public function test_transient_exception_constant_value(): void
    {
        // Locks the constant to a stable string so middleware + recorders can
        // never drift out of sync via independent magic-string copies.
        self::assertSame('exception', Stopwatch::TRANSIENT_EXCEPTION);
    }

    private function makeStopwatch(): Stopwatch
    {
        return Stopwatch::new(clock: new FakeClock());
    }

    private function captureRecorder(callable $callback): RunRecorder
    {
        return new class ($callback) implements RunRecorder {
            /** @param callable(Stopwatch, array<string, scalar|null>): void $callback */
            public function __construct(private $callback) {}

            public function record(Stopwatch $stopwatch, array $context): void
            {
                ($this->callback)($stopwatch, $context);
            }
        };
    }
}
