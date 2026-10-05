<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\RunLog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Depends;
use SanderMuller\Stopwatch\FakeClock;
use SanderMuller\Stopwatch\RunLog\DebugMode;
use SanderMuller\Stopwatch\RunLog\JsonlRunStream;
use SanderMuller\Stopwatch\RunLog\RunLogStore;
use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\StopwatchMiddleware;
use SanderMuller\Stopwatch\Tests\TestCase;
use stdClass;

final class DebugStreamTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        DebugMode::resetWarning();
        $this->dir = sys_get_temp_dir() . '/stopwatch-debug-' . uniqid();
        config()->set('stopwatch.run_log.path', $this->dir);
        config()->set('stopwatch.debug.enabled', true);
        config()->set('app.debug', true);
        $this->app->forgetInstance(Stopwatch::class);
        $this->app->forgetInstance(RunLogStore::class);
        $this->app->forgetInstance(JsonlRunStream::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_stream_writes_start_checkpoint_and_end_records(): void
    {
        $line = __LINE__ + 1;
        stopwatch()->probe('Loaded user', ['id' => 42]);
        stopwatch()->checkpoint('Rendered');
        $runId = (string) stopwatch()->runId();
        stopwatch()->finish();

        $records = $this->records($runId);

        self::assertSame(['start', 'checkpoint', 'checkpoint', 'end'], array_column($records, 'type'));
        self::assertSame($runId, $records[0]['run']);
        self::assertTrue($records[1]['probe']);
        self::assertSame(['id' => 42], $records[1]['metadata']);
        self::assertStringEndsWith('DebugStreamTest.php:' . $line, $records[1]['location']);
        self::assertFalse($records[2]['probe']);
        self::assertSame('finished', $records[3]['reason']);
        self::assertSame(2, $records[3]['checkpoints']);
        self::assertFileExists($this->dir . '/' . $runId . '.md');
    }

    public function test_a_run_without_checkpoints_leaves_no_stream_file(): void
    {
        stopwatch()->start();
        $runId = (string) stopwatch()->runId();
        stopwatch()->finish();

        self::assertFileDoesNotExist($this->dir . '/' . $runId . '.jsonl');
    }

    public function test_start_during_an_active_run_writes_a_restarted_end_record(): void
    {
        stopwatch()->checkpoint('First run');
        $first = (string) stopwatch()->runId();
        stopwatch()->start();
        stopwatch()->checkpoint('Second run');
        $second = (string) stopwatch()->runId();
        stopwatch()->finish();

        self::assertSame('restarted', $this->records($first)[2]['reason']);
        self::assertSame('finished', $this->records($second)[2]['reason']);
    }

    public function test_hot_loop_is_truncated_after_max_records(): void
    {
        config()->set('stopwatch.debug.max_records', 3);

        for ($i = 0; $i < 5; $i++) {
            stopwatch()->probe('loop', ['i' => $i]);
        }

        $runId = (string) stopwatch()->runId();
        stopwatch()->finish();

        $records = $this->records($runId);

        self::assertSame(['start', 'checkpoint', 'checkpoint', 'checkpoint', 'truncated', 'end'], array_column($records, 'type'));
        self::assertSame(3, $records[4]['after']);
        self::assertSame(5, $records[5]['checkpoints']);
        self::assertSame(2, $records[5]['dropped']);
    }

    public function test_unencodable_metadata_keeps_the_checkpoint_record(): void
    {
        stopwatch()->probe('Bad values', ['name' => "\xB1abc", 'ok' => 1, 'handle' => fopen('php://memory', 'rb')]);
        $runId = (string) stopwatch()->runId();

        $metadata = $this->records($runId)[1]['metadata'];

        self::assertSame("\u{FFFD}abc", $metadata['name']);
        self::assertSame(1, $metadata['ok']);
        self::assertNull($metadata['handle']);
    }

    public function test_the_record_cap_resets_for_the_next_run(): void
    {
        config()->set('stopwatch.debug.max_records', 2);

        foreach ([1, 2, 3] as $i) {
            stopwatch()->probe('first run', ['i' => $i]);
        }

        stopwatch()->start();
        stopwatch()->probe('second run');
        $second = (string) stopwatch()->runId();
        stopwatch()->finish();

        $records = $this->records($second);
        self::assertSame(['start', 'checkpoint', 'end'], array_column($records, 'type'));
        self::assertSame(0, $records[2]['dropped']);
    }

    public function test_a_restarted_run_without_checkpoints_leaves_no_file(): void
    {
        stopwatch()->start();
        $first = (string) stopwatch()->runId();
        stopwatch()->start();

        self::assertFileDoesNotExist($this->dir . '/' . $first . '.jsonl');
    }

    public function test_app_debug_false_in_the_local_environment_still_records(): void
    {
        config()->set('app.debug', false);
        $this->app->detectEnvironment(static fn (): string => 'local');

        stopwatch()->probe('Local with APP_DEBUG=false');

        self::assertSame('checkpoint', $this->records((string) stopwatch()->runId())[1]['type']);
    }

    public function test_without_debug_mode_the_wired_singleton_shows_no_location_and_no_stream(): void
    {
        config()->set('stopwatch.debug.enabled', false);
        config()->set('stopwatch.run_log.enabled', true);
        config()->set('stopwatch.run_log.min_duration_ms', 0);

        stopwatch()->checkpoint('Plain run');
        $runId = (string) stopwatch()->runId();

        self::assertStringNotContainsString('| Location |', stopwatch()->toMarkdown());
        self::assertFileDoesNotExist($this->dir . '/' . $runId . '.jsonl');
        self::assertFileExists($this->dir . '/' . $runId . '.md');
    }

    public function test_large_metadata_values_are_capped_with_a_marker(): void
    {
        config()->set('stopwatch.debug.value_max_bytes', 50);

        stopwatch()->probe('Big', ['blob' => str_repeat('z', 200), 'small' => 1]);
        $runId = (string) stopwatch()->runId();

        $metadata = $this->records($runId)[1]['metadata'];

        self::assertStringStartsWith('…(truncated 152 bytes) "zzz', $metadata['blob']);
        self::assertSame(1, $metadata['small']);
    }

    public function test_app_debug_false_in_production_blocks_debug_mode_with_one_warning(): void
    {
        config()->set('app.debug', false);
        $this->app->detectEnvironment(static fn (): string => 'production');
        Log::spy();

        stopwatch()->checkpoint('Step');
        $runId = (string) stopwatch()->runId();
        stopwatch()->finish();
        $this->app->forgetInstance(Stopwatch::class);
        stopwatch()->checkpoint('Second singleton');

        self::assertFileDoesNotExist($this->dir . '/' . $runId . '.jsonl');
        Log::shouldHaveReceived('warning')->once()->with('Stopwatch debug mode is off: app.debug is false and APP_ENV is production.');
        self::assertSame('app.debug is false and APP_ENV is production', $this->app->make(RunLogStore::class)->debugBlocked()['reason'] ?? null);
    }

    public function test_app_debug_false_in_the_testing_environment_still_records(): void
    {
        // phpunit.xml often sets APP_DEBUG=false, and the test run is where an agent debugs.
        config()->set('app.debug', false);

        stopwatch()->probe('In a test with APP_DEBUG=false');
        $runId = (string) stopwatch()->runId();

        self::assertSame('checkpoint', $this->records($runId)[1]['type']);
    }

    public function test_an_active_debug_mode_clears_an_old_blocked_note(): void
    {
        $this->app->make(RunLogStore::class)->writeDebugBlocked('app.debug is false and APP_ENV is production');

        stopwatch()->checkpoint('Now active');

        self::assertNull($this->app->make(RunLogStore::class)->debugBlocked());
    }

    public function test_octane_blocks_debug_mode_with_a_warning(): void
    {
        $this->app->instance('octane', new stdClass());
        Log::spy();

        stopwatch()->checkpoint('Step');
        $runId = (string) stopwatch()->runId();

        self::assertFileDoesNotExist($this->dir . '/' . $runId . '.jsonl');
        Log::shouldHaveReceived('warning')->once()->with('Stopwatch debug mode is off: the app runs under Octane.');
    }

    public function test_disabled_stopwatch_writes_nothing_and_does_not_warn(): void
    {
        config()->set('stopwatch.enabled', false);
        Log::spy();

        stopwatch()->checkpoint('Step');

        self::assertDirectoryDoesNotExist($this->dir);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_preset_wins_over_configured_run_log_values(): void
    {
        // Values set directly in config, as with a cached config where env() is null.
        config()->set('stopwatch.run_log.enabled', false);
        config()->set('stopwatch.run_log.min_duration_ms', 100);
        config()->set('stopwatch.run_log.skip_empty', true);

        stopwatch()->checkpoint('Fast');
        $runId = (string) stopwatch()->runId();
        stopwatch()->finish();

        self::assertFileExists($this->dir . '/' . $runId . '.md');
        self::assertSame(0, config('stopwatch.run_log.min_duration_ms'));
        self::assertTrue(config('stopwatch.run_log.auto_lifecycle'));
    }

    public function test_existing_gitignore_without_a_trailing_newline_gets_the_lines_on_their_own_line(): void
    {
        mkdir($this->dir, 0755, true);
        file_put_contents($this->dir . '/.gitignore', '*.md');

        stopwatch()->checkpoint('Step');

        self::assertSame("*.md\n*.jsonl\n.debug-blocked\n", file_get_contents($this->dir . '/.gitignore'));
    }

    public function test_existing_gitignore_without_jsonl_gets_the_line_appended(): void
    {
        mkdir($this->dir, 0755, true);
        file_put_contents($this->dir . '/.gitignore', "*.md\n");

        stopwatch()->checkpoint('Step');

        self::assertSame("*.md\n*.jsonl\n.debug-blocked\n", file_get_contents($this->dir . '/.gitignore'));
    }

    public function test_unwritable_runs_dir_logs_one_warning_and_continues(): void
    {
        $blocker = sys_get_temp_dir() . '/stopwatch-blocker-' . uniqid();
        file_put_contents($blocker, 'not a directory');
        config()->set('stopwatch.run_log.path', $blocker . '/runs');
        Log::spy();

        stopwatch()->checkpoint('One');
        stopwatch()->checkpoint('Two');
        stopwatch()->finish();
        @unlink($blocker);

        Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message): bool => str_starts_with($message, 'Stopwatch debug stream could not write'));
    }

    public function test_a_forked_child_writes_nothing_to_the_parent_stream(): void
    {
        $pid = 100;
        $store = new RunLogStore($this->dir);
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->addCheckpointListener(new JsonlRunStream($store, pid: static function () use (&$pid): int {
            return $pid;
        }));

        $stopwatch->checkpoint('Parent');
        $pid = 200;
        $stopwatch->checkpoint('Child');
        $stopwatch->finish();
        $pid = 100;

        self::assertSame(['start', 'checkpoint'], array_column($this->records((string) $stopwatch->runId()), 'type'));
    }

    public function test_exit_writes_a_shutdown_end_record(): void
    {
        mkdir($this->dir, 0755, true);
        $script = $this->dir . '/exit.php';
        file_put_contents($script, '<?php
            require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';
            $store = new SanderMuller\Stopwatch\RunLog\RunLogStore(' . var_export($this->dir, true) . ');
            $sw = SanderMuller\Stopwatch\Stopwatch::new();
            $sw->addCheckpointListener(new SanderMuller\Stopwatch\RunLog\JsonlRunStream($store));
            $sw->probe("before exit");
            echo $sw->runId();
            exit(3);
        ');

        $runId = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script));
        $records = $this->records($runId);

        self::assertSame('end', $records[2]['type']);
        self::assertSame('shutdown', $records[2]['reason']);
    }

    public function test_unset_without_trackers_writes_shutdown_at_once(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->addCheckpointListener(new JsonlRunStream(new RunLogStore($this->dir)));
        $stopwatch->probe('No finish');
        $runId = (string) $stopwatch->runId();
        unset($stopwatch);

        self::assertSame('shutdown', $this->records($runId)[2]['reason']);
    }

    public function test_singleton_with_trackers_and_no_finish_creates_a_run(): string
    {
        config()->set('stopwatch.track_queries', true);
        config()->set('stopwatch.track_memory', true);
        config()->set('stopwatch.run_log.path', sys_get_temp_dir() . '/stopwatch-debug-shared');

        DB::select('SELECT 1');
        stopwatch()->probe('Kept alive by a tracker cycle');
        self::assertNotNull(stopwatch()->runId());

        return sys_get_temp_dir() . '/stopwatch-debug-shared/' . stopwatch()->runId() . '.jsonl';
    }

    #[Depends('test_singleton_with_trackers_and_no_finish_creates_a_run')]
    public function test_tracker_cycle_still_gets_a_shutdown_record_after_gc(string $path): void
    {
        gc_collect_cycles();

        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $last = json_decode((string) end($lines), true);
        File::deleteDirectory(dirname($path));

        self::assertIsArray($last);
        self::assertSame('shutdown', $last['reason']);
    }

    public function test_start_record_carries_the_request_url_and_method(): void
    {
        Route::post('/debug-url', static function (): string {
            stopwatch()->probe('Inside');

            return (string) stopwatch()->runId();
        })->middleware(StopwatchMiddleware::autoStart());

        $runId = $this->post('/debug-url?token=secret')->assertOk()->getContent();
        $start = $this->records((string) $runId)[0];

        self::assertSame('POST', $start['method']);
        self::assertStringEndsWith('/debug-url', $start['url']);
    }

    public function test_location_column_shows_only_in_debug_mode(): void
    {
        stopwatch()->checkpoint('Debug on');
        $markdown = stopwatch()->toMarkdown();
        $html = stopwatch()->toHtml();

        self::assertStringContainsString('| Location |', $markdown);
        self::assertStringContainsString('class="sw-location"', $html);

        $plain = Stopwatch::new(clock: new FakeClock());
        $plain->checkpoint('Debug off');

        self::assertStringNotContainsString('Location', $plain->toMarkdown());
        self::assertStringNotContainsString('class="sw-location"', $plain->toHtml());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function records(string $runId): array
    {
        $path = $this->dir . '/' . $runId . '.jsonl';
        self::assertFileExists($path);

        return array_map(
            static fn (string $line): array => (array) json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($path, FILE_IGNORE_NEW_LINES) ?: [],
        );
    }
}
