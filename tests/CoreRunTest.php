<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use SanderMuller\Stopwatch\FakeClock;
use SanderMuller\Stopwatch\RunLog\CheckpointListener;
use SanderMuller\Stopwatch\RunLog\MarkdownRunRecorder;
use SanderMuller\Stopwatch\RunLog\RunEndReason;
use SanderMuller\Stopwatch\RunLog\RunLogStore;
use SanderMuller\Stopwatch\RunLog\RunRecorder;
use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\StopwatchCheckpoint;
use SanderMuller\Stopwatch\StopwatchMiddleware;

final class CoreRunTest extends TestCase
{
    protected function tearDown(): void
    {
        Str::createUlidsNormally();

        parent::tearDown();
    }

    public function test_standalone_run_never_creates_a_ulid(): void
    {
        Str::createUlidsUsing(static fn () => throw new RuntimeException('Str::ulid() must not be called'));

        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->start();
        $stopwatch->checkpoint('One');
        $stopwatch->probe('Two', ['x' => 1]);
        $stopwatch->finish();

        self::assertStringContainsString('Two', $stopwatch->toMarkdown());
    }

    public function test_run_id_is_null_before_start_stable_within_a_run_and_new_after_start(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        self::assertNull($stopwatch->runId());

        $stopwatch->checkpoint('auto-start');
        $first = $stopwatch->runId();
        $stopwatch->checkpoint('second');

        self::assertNotNull($first);
        self::assertSame($first, $stopwatch->runId());

        $stopwatch->start();
        self::assertNotSame($first, $stopwatch->runId());
    }

    public function test_markdown_run_file_is_named_after_the_run_id(): void
    {
        $dir = sys_get_temp_dir() . '/stopwatch-core-' . uniqid();
        $store = new RunLogStore($dir);
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->recordRunsTo(new MarkdownRunRecorder($store, minDurationMs: null));

        $stopwatch->checkpoint('Step');
        $runId = $stopwatch->runId();
        $stopwatch->finish();

        self::assertFileExists($dir . '/' . $runId . '.md');
    }

    public function test_probe_records_like_checkpoint_with_probe_flag(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->checkpoint('Regular', ['a' => 1]);
        $stopwatch->probe('Temporary', ['b' => 2]);

        [$regular, $probe] = $stopwatch->toArray()['checkpoints'];

        self::assertFalse($regular['probe']);
        self::assertTrue($probe['probe']);
        self::assertSame('Temporary', $probe['label']);
        self::assertSame(['b' => 2], $probe['metadata']);
    }

    public function test_location_points_at_the_calling_line(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());

        $checkpointLine = __LINE__ + 1;
        $stopwatch->checkpoint('Direct');
        $measureLine = __LINE__ + 1;
        $stopwatch->measure('Measured', static fn (): int => 1);
        $probeLine = __LINE__ + 1;
        stopwatch()->probe('Via helper');

        $locations = array_column($stopwatch->toArray()['checkpoints'], 'location');
        $helperLocation = stopwatch()->toArray()['checkpoints'][0]['location'];

        self::assertStringEndsWith('CoreRunTest.php:' . $checkpointLine, (string) $locations[0]);
        self::assertStringEndsWith('CoreRunTest.php:' . $measureLine, (string) $locations[1]);
        self::assertStringEndsWith('CoreRunTest.php:' . $probeLine, (string) $helperLocation);
    }

    public function test_listener_hears_checkpoints_and_each_end_reason(): void
    {
        $listener = new class implements CheckpointListener {
            /** @var list<string> */
            public array $events = [];

            public function onCheckpoint(Stopwatch $stopwatch, StopwatchCheckpoint $checkpoint): void
            {
                $this->events[] = 'checkpoint:' . $checkpoint->label;
            }

            public function onRunEnd(Stopwatch $stopwatch, RunEndReason $reason): void
            {
                $this->events[] = 'end:' . $reason->value;
            }
        };

        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->addCheckpointListener($listener);

        $stopwatch->start();
        $stopwatch->checkpoint('A');
        $stopwatch->start();
        $stopwatch->checkpoint('B');
        $stopwatch->finish();
        $stopwatch->start();
        $stopwatch->checkpoint('C');
        unset($stopwatch);

        self::assertSame(
            ['checkpoint:A', 'end:restarted', 'checkpoint:B', 'end:finished', 'checkpoint:C', 'end:shutdown'],
            $listener->events,
        );
    }

    public function test_a_throwing_listener_cannot_break_the_run(): void
    {
        Log::spy();
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->addCheckpointListener(new class implements CheckpointListener {
            public function onCheckpoint(Stopwatch $stopwatch, StopwatchCheckpoint $checkpoint): void
            {
                throw new RuntimeException('listener broke');
            }

            public function onRunEnd(Stopwatch $stopwatch, RunEndReason $reason): void
            {
                throw new RuntimeException('listener broke');
            }
        });

        $stopwatch->checkpoint('Still recorded');
        $stopwatch->finish();

        self::assertSame(['Still recorded'], array_column($stopwatch->toArray()['checkpoints'], 'label'));
        Log::shouldHaveReceived('warning')->twice()->withArgs(static fn (string $message): bool => str_starts_with($message, 'Stopwatch checkpoint listener failed'));
    }

    public function test_start_before_any_run_reports_no_restart(): void
    {
        $listener = new class implements CheckpointListener {
            public int $ends = 0;

            public function onCheckpoint(Stopwatch $stopwatch, StopwatchCheckpoint $checkpoint): void {}

            public function onRunEnd(Stopwatch $stopwatch, RunEndReason $reason): void
            {
                $this->ends++;
            }
        };

        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->addCheckpointListener($listener);
        $stopwatch->start();
        $stopwatch->finish();
        $stopwatch->start();
        $stopwatch->finish();

        self::assertSame(2, $listener->ends);
    }

    public function test_two_autostart_requests_in_one_test_give_two_runs_with_url_context_at_start(): void
    {
        $runs = [];
        $urlsSeenInsideRequest = [];
        stopwatch()->recordRunsTo(new class ($runs) implements RunRecorder {
            /** @param list<array{labels: list<string>, url: mixed}> $runs */
            public function __construct(private array &$runs) {}

            public function record(Stopwatch $stopwatch, array $context): void
            {
                $this->runs[] = [
                    'labels' => array_map(static fn (StopwatchCheckpoint $cp): string => $cp->label, $stopwatch->checkpoints()),
                    'url' => $context['url'] ?? null,
                ];
            }
        });

        foreach (['one', 'two'] as $name) {
            Route::get('/core-' . $name, static function () use ($name, &$urlsSeenInsideRequest): string {
                $urlsSeenInsideRequest[] = stopwatch()->resolveRunContext()['url'] ?? null;
                stopwatch()->checkpoint('req-' . $name);

                return 'ok';
            })->middleware(StopwatchMiddleware::autoStart());
        }

        $this->get('/core-one')->assertOk();
        $this->get('/core-two')->assertOk();

        self::assertCount(2, $runs);
        self::assertSame(['req-one'], $runs[0]['labels']);
        self::assertSame(['req-two'], $runs[1]['labels']);
        self::assertStringEndsWith('/core-one', (string) $urlsSeenInsideRequest[0]);
        self::assertStringEndsWith('/core-two', (string) $urlsSeenInsideRequest[1]);
    }
}
