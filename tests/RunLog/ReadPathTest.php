<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\RunLog;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use SanderMuller\Stopwatch\RunLog\DebugNotice;
use SanderMuller\Stopwatch\RunLog\RunLogStore;
use SanderMuller\Stopwatch\Tests\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class ReadPathTest extends TestCase
{
    private const string OLD = '01HZAA0000000000000000000A';

    private const string MID = '01HZBB0000000000000000000A';

    private const string NEW = '01HZCC0000000000000000000A';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/stopwatch-read-' . uniqid();
        config()->set('stopwatch.run_log.path', $this->dir);
        $this->app->forgetInstance(RunLogStore::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_list_shows_the_state_of_each_kind_of_run(): void
    {
        $this->markdownRun(self::OLD);
        $this->streamRun(self::MID, end: 'shutdown');
        $this->streamRun(self::NEW, end: null);
        $this->streamRun('01HZDD0000000000000000000A', end: 'restarted');

        $rows = json_decode($this->runCommand('stopwatch:runs:list', ['--format' => 'json']), true);
        $states = array_column((array) $rows, 'state', 'id');

        self::assertSame('finished', $states[self::OLD]);
        self::assertSame('shutdown', $states[self::MID]);
        self::assertSame('unfinished', $states[self::NEW]);
        self::assertSame('restarted', $states['01HZDD0000000000000000000A']);

        $table = $this->runCommand('stopwatch:runs:list');
        self::assertStringContainsString('State', $table);
        self::assertStringContainsString('unfinished', $table);
    }

    public function test_a_run_with_both_files_is_listed_once_as_finished(): void
    {
        $this->markdownRun(self::OLD);
        $this->streamRun(self::OLD, end: 'finished');

        $rows = (array) json_decode($this->runCommand('stopwatch:runs:list', ['--format' => 'json']), true);

        self::assertCount(1, $rows);
        self::assertSame('finished', $rows[0]['state']);
    }

    public function test_show_latest_picks_the_newest_run_across_both_extensions(): void
    {
        $this->markdownRun(self::OLD);
        $this->streamRun(self::NEW, end: null);

        $output = $this->runCommand('stopwatch:runs:show', ['id' => 'latest']);

        self::assertStringContainsString('# Stopwatch run (unfinished)', $output);
        self::assertStringContainsString(self::NEW, $output);
        self::assertStringContainsString('none recorded', $output);
        self::assertStringContainsString('app/Probe.php:12', $output);
        self::assertStringContainsString('{"user":42}', $output);
    }

    public function test_show_prints_markdown_when_the_run_finished(): void
    {
        $this->markdownRun(self::OLD);
        $this->streamRun(self::OLD, end: 'finished');

        $output = $this->runCommand('stopwatch:runs:show', ['id' => self::OLD]);

        self::assertStringContainsString('# Stopwatch profile', $output);
        self::assertStringNotContainsString('# Stopwatch run (', $output);
    }

    public function test_show_json_prints_the_stream_records(): void
    {
        $this->streamRun(self::MID, end: 'shutdown');

        $records = json_decode($this->runCommand('stopwatch:runs:show', ['id' => self::MID, '--format' => 'json']), true);

        self::assertIsArray($records);
        self::assertSame(['start', 'checkpoint', 'end'], array_column($records, 'type'));
    }

    public function test_show_json_fails_for_a_run_without_a_stream(): void
    {
        $this->markdownRun(self::OLD);

        $this->artisan('stopwatch:runs:show', ['id' => self::OLD, '--format' => 'json'])
            ->expectsOutputToContain('has no JSONL stream. Record it with STOPWATCH_DEBUG=true.')
            ->assertFailed();
    }

    public function test_show_latest_with_no_runs_fails(): void
    {
        $this->artisan('stopwatch:runs:show', ['id' => 'latest'])
            ->expectsOutputToContain('No runs recorded yet.')
            ->assertFailed();
    }

    public function test_empty_messages_name_the_guard_that_blocks_debug_mode(): void
    {
        config()->set('stopwatch.debug.enabled', true);
        config()->set('app.debug', false);
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->artisan('stopwatch:runs:show', ['id' => 'latest'])
            ->expectsOutputToContain('Debug mode is off: app.debug is false and APP_ENV is production.')
            ->assertFailed();

        $this->artisan('stopwatch:runs:list')
            ->expectsOutputToContain('Debug mode is off: app.debug is false and APP_ENV is production.')
            ->assertSuccessful();
    }

    public function test_a_block_noted_by_another_process_is_reported(): void
    {
        // The reader runs in a different process and environment than the blocked run.
        $this->app->make(RunLogStore::class)->writeDebugBlocked('app.debug is false and APP_ENV is staging');

        self::assertMatchesRegularExpression(
            '/^Debug mode was blocked in another process at \S+: app\.debug is false and APP_ENV is staging\.$/',
            (string) DebugNotice::blocked(),
        );

        $this->artisan('stopwatch:runs:show', ['id' => 'latest'])
            ->expectsOutputToContain('Debug mode was blocked in another process at')
            ->assertFailed();

        $this->markdownRun(self::OLD);

        $this->artisan('stopwatch:runs:list')
            ->expectsOutputToContain('Debug mode was blocked in another process at')
            ->assertSuccessful();
    }

    public function test_frontmatter_filters_exclude_stream_only_runs(): void
    {
        $this->markdownRun(self::OLD, exceedsSlowThreshold: true);
        $this->streamRun(self::NEW, end: null);

        $rows = (array) json_decode($this->runCommand('stopwatch:runs:list', ['--slow' => true, '--format' => 'json']), true);

        self::assertSame([self::OLD], array_column($rows, 'id'));
    }

    public function test_prune_and_clear_treat_both_files_as_one_run(): void
    {
        $this->markdownRun(self::OLD);
        $this->streamRun(self::OLD, end: 'finished');
        $this->streamRun(self::NEW, end: null);
        $store = $this->app->make(RunLogStore::class);

        self::assertSame(1, $store->pruneByCount(1));
        self::assertFileDoesNotExist($this->dir . '/' . self::OLD . '.md');
        self::assertFileDoesNotExist($this->dir . '/' . self::OLD . '.jsonl');
        self::assertSame(1, $store->clear());
        self::assertFileDoesNotExist($this->dir . '/' . self::NEW . '.jsonl');
    }

    public function test_a_half_written_last_line_reads_as_unfinished(): void
    {
        $this->streamRun(self::NEW, end: null);
        file_put_contents($this->dir . '/' . self::NEW . '.jsonl', '{"type":"checkp', FILE_APPEND);

        $rows = (array) json_decode($this->runCommand('stopwatch:runs:list', ['--format' => 'json']), true);
        $output = $this->runCommand('stopwatch:runs:show', ['id' => self::NEW]);

        self::assertSame('unfinished', $rows[0]['state']);
        self::assertStringContainsString('| 1 | Probe | yes |', $output);
    }

    public function test_show_renders_a_stream_run_that_ended_and_was_truncated(): void
    {
        $store = $this->app->make(RunLogStore::class);
        $this->streamRun(self::MID, end: null);
        $store->appendLine(self::MID, (string) json_encode(['type' => 'truncated', 'after' => 1]));
        $store->appendLine(self::MID, (string) json_encode(['type' => 'end', 'reason' => 'shutdown', 'duration_ms' => 12.5, 'checkpoints' => 5, 'dropped' => 4]));

        $output = $this->runCommand('stopwatch:runs:show', ['id' => self::MID]);

        self::assertStringContainsString('# Stopwatch run (shutdown)', $output);
        self::assertStringContainsString('- **Checkpoints:** 1 of 5', $output);
        self::assertStringContainsString('- **End:** shutdown after 12.5ms', $output);
        self::assertStringContainsString('- **Truncated:** records after #1 were counted, not written', $output);
    }

    public function test_list_names_the_job_of_a_job_run(): void
    {
        $this->app->make(RunLogStore::class)->appendLine(self::NEW, (string) json_encode(['type' => 'start', 'run' => self::NEW, 'job' => 'App\\Jobs\\SendInvoice']));

        self::assertStringContainsString('job App\\Jobs\\SendInvoice', $this->runCommand('stopwatch:runs:list'));
    }

    private function markdownRun(string $id, bool $exceedsSlowThreshold = false): void
    {
        $this->app->make(RunLogStore::class)->write($id, implode("\n", [
            '---',
            'id: ' . $id,
            'recorded_at: 2026-10-05T12:00:00.000+00:00',
            'duration_ms: 120',
            'checkpoints: 1',
            'url: /finished',
            'method: GET',
            'status: 200',
            'exceeds_slow_threshold: ' . ($exceedsSlowThreshold ? 'true' : 'false'),
            '---',
            '',
            '# Stopwatch profile',
            '',
        ]));
    }

    private function streamRun(string $id, ?string $end): void
    {
        $store = $this->app->make(RunLogStore::class);
        $store->appendLine($id, (string) json_encode(['type' => 'start', 'run' => $id, 'at' => '2026-10-05T12:00:00.000+00:00', 'url' => '/stream', 'method' => 'GET', 'command' => null, 'job' => null]));
        $store->appendLine($id, (string) json_encode(['type' => 'checkpoint', 'i' => 1, 'label' => 'Probe', 'probe' => true, 'location' => 'app/Probe.php:12', 'delta_ms' => 1.5, 'total_ms' => 1.5, 'metadata' => ['user' => 42], 'queries' => null, 'query_ms' => null, 'http' => null, 'http_ms' => null, 'memory_delta' => null]));

        if ($end !== null) {
            $store->appendLine($id, (string) json_encode(['type' => 'end', 'reason' => $end, 'duration_ms' => 2.0, 'checkpoints' => 1, 'dropped' => 0]));
        }
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function runCommand(string $command, array $parameters = []): string
    {
        $output = new BufferedOutput();
        $this->app->make(Kernel::class)->call($command, $parameters, $output);

        return $output->fetch();
    }
}
