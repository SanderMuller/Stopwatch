<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\RunLog;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use SanderMuller\Stopwatch\FakeClock;
use SanderMuller\Stopwatch\Notifications\StopwatchNotificationChannel;
use SanderMuller\Stopwatch\RunLog\RunRecorder;
use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\Tests\TestCase;

final class StopwatchRunLogIntegrationTest extends TestCase
{
    public function test_recorder_fires_on_finish_with_resolved_context(): void
    {
        $captured = null;
        $stopwatch = $this->makeStopwatch();
        $stopwatch->recordRunsTo($this->makeRecorder(function (Stopwatch $sw, array $ctx) use (&$captured): void {
            $captured = $ctx;
        }));

        $stopwatch->start();
        $stopwatch->checkpoint('Step');
        $stopwatch->withRunContext(['url' => '/x', 'method' => 'GET']);
        $stopwatch->finish();

        self::assertSame(['url' => '/x', 'method' => 'GET'], $captured);
    }

    public function test_recorder_fires_only_once_when_finish_called_repeatedly(): void
    {
        $count = 0;
        $stopwatch = $this->makeStopwatch();
        $stopwatch->recordRunsTo($this->makeRecorder(function () use (&$count): void {
            $count++;
        }))->start();
        $stopwatch->checkpoint('Step');

        $stopwatch->finish();
        $stopwatch->finish();
        $stopwatch->toMarkdown();
        $stopwatch->toArray();

        self::assertSame(1, $count);
    }

    public function test_throwing_recorder_does_not_propagate(): void
    {
        Log::spy();
        $stopwatch = $this->makeStopwatch();
        $stopwatch->recordRunsTo($this->makeRecorder(function (): void {
            throw new RuntimeException('boom');
        }))->start();
        $stopwatch->checkpoint('Step');

        $stopwatch->finish();

        self::expectNotToPerformAssertions();
    }

    public function test_throwing_notification_does_not_block_recorders(): void
    {
        $recorderCalled = false;
        $stopwatch = $this->makeStopwatch();
        $stopwatch->recordRunsTo($this->makeRecorder(function () use (&$recorderCalled): void {
            $recorderCalled = true;
        }));

        $throwingChannel = new class implements StopwatchNotificationChannel {
            public function notify(Stopwatch $stopwatch): void
            {
                throw new RuntimeException('channel boom');
            }
        };

        Log::spy();

        $stopwatch->notifyUsing([$throwingChannel])
            ->notifyIfSlowerThan(0)
            ->start();
        $stopwatch->checkpoint('Step');
        $stopwatch->finish();

        self::assertTrue($recorderCalled, 'recorder should have fired even though the notification channel threw');
    }

    public function test_record_runs_to_replaces_not_appends(): void
    {
        $first = 0;
        $second = 0;

        $stopwatch = $this->makeStopwatch();
        $stopwatch->recordRunsTo($this->makeRecorder(function () use (&$first): void {
            $first++;
        }));
        $stopwatch->recordRunsTo($this->makeRecorder(function () use (&$second): void {
            $second++;
        }));

        $stopwatch->start();
        $stopwatch->checkpoint('Step');
        $stopwatch->finish();

        self::assertSame(0, $first, 'old recorder must be replaced');
        self::assertSame(1, $second, 'new recorder must fire once');
    }

    public function test_context_providers_survive_reset_and_evaluate_at_finish(): void
    {
        $captured = null;
        $stopwatch = $this->makeStopwatch();

        $stopwatch->pushRunContextProvider(static fn (): array => ['command' => 'app:reindex']);
        $stopwatch->recordRunsTo($this->makeRecorder(function (Stopwatch $sw, array $ctx) use (&$captured): void {
            $captured = $ctx;
        }));

        // start() → reset() → checkpoint(); provider evaluated at finish() so no race.
        $stopwatch->start();
        $stopwatch->checkpoint('Step');
        $stopwatch->finish();

        self::assertSame(['command' => 'app:reindex'], $captured);

        // Second run — provider still wired.
        $captured = null;
        $stopwatch->restart();
        $stopwatch->checkpoint('Again');
        $stopwatch->finish();

        self::assertSame(['command' => 'app:reindex'], $captured);
    }

    public function test_with_run_context_overrides_provider_on_collision(): void
    {
        $captured = null;
        $stopwatch = $this->makeStopwatch();
        $stopwatch->pushRunContextProvider(static fn (): array => ['url' => '/from-provider', 'method' => 'GET']);
        $stopwatch->recordRunsTo($this->makeRecorder(function (Stopwatch $sw, array $ctx) use (&$captured): void {
            $captured = $ctx;
        }));

        $stopwatch->start();
        $stopwatch->checkpoint('Step');
        $stopwatch->withRunContext(['url' => '/override']);
        $stopwatch->finish();

        self::assertSame('/override', $captured['url']);
        self::assertSame('GET', $captured['method']);
    }

    public function test_throwing_context_provider_does_not_block_recorders(): void
    {
        $captured = null;
        $stopwatch = $this->makeStopwatch();

        // First provider throws, second provider sets `command` — recorder must still receive `command`.
        $stopwatch->pushRunContextProvider(static function (): array {
            throw new RuntimeException('provider boom');
        });
        $stopwatch->pushRunContextProvider(static fn (): array => ['command' => 'app:still-fires']);
        $stopwatch->recordRunsTo($this->makeRecorder(function (Stopwatch $sw, array $ctx) use (&$captured): void {
            $captured = $ctx;
        }));

        $stopwatch->start();
        $stopwatch->checkpoint('Step');
        $stopwatch->finish();

        self::assertSame(['command' => 'app:still-fires'], $captured);
    }

    public function test_with_run_context_cleared_between_runs(): void
    {
        $captured = null;
        $stopwatch = $this->makeStopwatch();
        $stopwatch->recordRunsTo($this->makeRecorder(function (Stopwatch $sw, array $ctx) use (&$captured): void {
            $captured = $ctx;
        }));

        $stopwatch->start();
        $stopwatch->checkpoint('Step');
        $stopwatch->withRunContext(['url' => '/first']);
        $stopwatch->finish();
        self::assertSame(['url' => '/first'], $captured);

        // Both reset() and finish() clear per-run context, so the second run starts clean —
        // even if the first run was abandoned mid-flight without finish().
        $captured = null;
        $stopwatch->restart();
        $stopwatch->checkpoint('Step');
        $stopwatch->finish();

        self::assertSame([], $captured);
    }

    public function test_with_run_context_cleared_on_restart_when_first_run_abandoned(): void
    {
        $captured = null;
        $stopwatch = $this->makeStopwatch();
        $stopwatch->recordRunsTo($this->makeRecorder(function (Stopwatch $sw, array $ctx) use (&$captured): void {
            $captured = $ctx;
        }));

        $stopwatch->start();
        $stopwatch->checkpoint('First');
        $stopwatch->withRunContext(['url' => '/abandoned']);
        // First run never finishes — user calls restart() instead.
        $stopwatch->restart();
        $stopwatch->checkpoint('Second');
        $stopwatch->finish();

        self::assertSame([], $captured, 'context from the abandoned first run must not leak into the second');
    }

    private function makeStopwatch(): Stopwatch
    {
        return Stopwatch::new(clock: new FakeClock());
    }

    private function makeRecorder(callable $callback): RunRecorder
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
