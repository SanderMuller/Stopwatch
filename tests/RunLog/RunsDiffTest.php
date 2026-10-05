<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\RunLog;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use SanderMuller\Stopwatch\RunLog\RunLogStore;
use SanderMuller\Stopwatch\Tests\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class RunsDiffTest extends TestCase
{
    private const string BEFORE = '01HZAA0000000000000000000A';

    private const string AFTER = '01HZBB0000000000000000000A';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/stopwatch-diff-' . uniqid();
        config()->set('stopwatch.run_log.path', $this->dir);
        $this->app->forgetInstance(RunLogStore::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_rows_match_by_label_and_occurrence(): void
    {
        $this->stream(self::BEFORE, [
            ['Load', 10.0, ['total' => 100], 5],
            ['Item', 1.0, [], null],
            ['Item', 2.0, [], null],
            ['Item', 3.0, [], null],
            ['Removed', 4.0, [], null],
        ]);
        $this->stream(self::AFTER, [
            ['Load', 4.0, ['total' => 90], 1],
            ['Item', 1.5, [], null],
            ['Item', 2.5, [], null],
            ['Added', 1.0, [], null],
        ]);

        $diff = json_decode($this->runCommand('stopwatch:runs:diff', ['a' => self::BEFORE, 'b' => self::AFTER, '--format' => 'json']), true);

        self::assertIsArray($diff);
        self::assertSame(['Load', 'Item', 'Item'], array_column($diff['rows'], 'label'));
        self::assertSame([1, 1, 2], array_column($diff['rows'], 'occurrence'));
        self::assertSame(-6, (int) $diff['rows'][0]['change_ms']);
        self::assertSame(-60, (int) $diff['rows'][0]['change_pct']);
        self::assertSame(5, $diff['rows'][0]['queries_a']);
        self::assertSame(1, $diff['rows'][0]['queries_b']);
        self::assertSame(['total' => ['a' => '100', 'b' => '90']], $diff['rows'][0]['metadata_changes']);
        self::assertSame(['Item', 'Removed'], $diff['only_in_a']);
        self::assertSame(['Added'], $diff['only_in_b']);
    }

    public function test_markdown_output_lists_changes_and_one_sided_rows(): void
    {
        $this->stream(self::BEFORE, [['Load', 10.0, ['total' => 100], null], ['Gone', 1.0, [], null]]);
        $this->stream(self::AFTER, [['Load', 4.0, ['total' => 90], null]]);

        $output = $this->runCommand('stopwatch:runs:diff', ['a' => self::BEFORE, 'b' => 'latest']);

        self::assertStringContainsString('| Load | 10ms | 4ms | -6ms (-60%)', $output);
        self::assertStringContainsString('- Load · total: 100 -> 90', $output);
        self::assertStringContainsString("## Only in a\n\n- Gone", $output);
    }

    public function test_a_run_without_a_stream_fails_with_a_hint(): void
    {
        $this->app->make(RunLogStore::class)->write(self::BEFORE, "---\nid: " . self::BEFORE . "\nduration_ms: 1\n---\n");
        $this->stream(self::AFTER, [['Load', 4.0, [], null]]);

        $this->artisan('stopwatch:runs:diff', ['a' => self::BEFORE, 'b' => self::AFTER])
            ->expectsOutputToContain('has no JSONL stream. Record it with STOPWATCH_DEBUG=true.')
            ->assertFailed();
    }

    public function test_an_unknown_run_fails(): void
    {
        $this->stream(self::AFTER, [['Load', 4.0, [], null]]);

        $this->artisan('stopwatch:runs:diff', ['a' => 'nope', 'b' => self::AFTER])
            ->expectsOutputToContain('Run [nope] not found.')
            ->assertFailed();
    }

    public function test_a_zero_ms_row_has_no_percentage(): void
    {
        $this->stream(self::BEFORE, [['Instant', 0.0, [], null]]);
        $this->stream(self::AFTER, [['Instant', 2.0, [], null]]);

        $diff = (array) json_decode($this->runCommand('stopwatch:runs:diff', ['a' => self::BEFORE, 'b' => self::AFTER, '--format' => 'json']), true);

        self::assertNull($diff['rows'][0]['change_pct']);
        self::assertStringContainsString('| Instant | 0ms | 2ms | +2ms |', $this->runCommand('stopwatch:runs:diff', ['a' => self::BEFORE, 'b' => self::AFTER]));
    }

    public function test_diff_latest_without_runs_fails(): void
    {
        $this->artisan('stopwatch:runs:diff', ['a' => 'latest', 'b' => 'latest'])
            ->expectsOutputToContain('No runs recorded yet.')
            ->assertFailed();
    }

    /**
     * @param list<array{0: string, 1: float, 2: array<string, mixed>, 3: int|null}> $checkpoints
     */
    private function stream(string $id, array $checkpoints): void
    {
        $store = $this->app->make(RunLogStore::class);
        $store->appendLine($id, (string) json_encode(['type' => 'start', 'run' => $id]));

        foreach ($checkpoints as $i => [$label, $delta, $metadata, $queries]) {
            $store->appendLine($id, (string) json_encode([
                'type' => 'checkpoint',
                'i' => $i + 1,
                'label' => $label,
                'delta_ms' => $delta,
                'metadata' => $metadata === [] ? null : $metadata,
                'queries' => $queries,
                'http' => null,
            ]));
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
