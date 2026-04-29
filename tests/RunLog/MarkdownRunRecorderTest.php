<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\RunLog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SanderMuller\Stopwatch\FakeClock;
use SanderMuller\Stopwatch\RunLog\MarkdownRunRecorder;
use SanderMuller\Stopwatch\RunLog\RunLogStore;
use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\Tests\TestCase;

final class MarkdownRunRecorderTest extends TestCase
{
    private string $tempDir;

    private RunLogStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/stopwatch-runlog-rec-' . bin2hex(random_bytes(6));
        $this->store = new RunLogStore($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();

        parent::tearDown();
    }

    public function test_skips_empty_runs_by_default(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock())->start()->finish();

        $this->makeRecorder()->record($stopwatch, []);

        self::assertSame([], glob($this->tempDir . '/*.md') ?: []);
    }

    public function test_writes_empty_runs_when_skip_empty_disabled(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock())->start()->finish();

        $this->makeRecorder(skipEmpty: false, minDurationMs: null)->record($stopwatch, []);

        $files = glob($this->tempDir . '/*.md') ?: [];
        self::assertCount(1, $files);
    }

    public function test_skips_runs_below_min_duration(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock())->start();
        $stopwatch->checkpoint('Quick');
        $stopwatch->finish();

        $this->makeRecorder(minDurationMs: 10_000)->record($stopwatch, []);

        self::assertSame([], glob($this->tempDir . '/*.md') ?: []);
    }

    public function test_writes_run_with_frontmatter_and_markdown_body(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock())->start();
        $stopwatch->checkpoint('Step 1');
        $stopwatch->checkpoint('Step 2');
        $stopwatch->finish();

        $this->makeRecorder(minDurationMs: null)->record($stopwatch, [
            'url' => '/admin/users',
            'method' => 'GET',
            'status' => 200,
        ]);

        $files = glob($this->tempDir . '/*.md') ?: [];
        self::assertCount(1, $files);

        $contents = (string) file_get_contents($files[0]);

        self::assertStringStartsWith('---', $contents);
        self::assertStringContainsString('url: /admin/users', $contents);
        self::assertStringContainsString('method: GET', $contents);
        self::assertStringContainsString('status: 200', $contents);
        self::assertStringContainsString('checkpoints: 2', $contents);
        self::assertStringContainsString('# Stopwatch profile', $contents);
        self::assertStringContainsString('Step 1', $contents);
        self::assertStringContainsString('Step 2', $contents);
    }

    public function test_writes_threw_flag_when_present_in_context(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock())->start();
        $stopwatch->checkpoint('Before crash');
        $stopwatch->finish();

        $this->makeRecorder(minDurationMs: null)->record($stopwatch, [
            'url' => '/explode',
            'threw' => true,
        ]);

        $contents = (string) file_get_contents((glob($this->tempDir . '/*.md') ?: [])[0]);

        self::assertStringContainsString('threw: true', $contents);
    }

    public function test_summary_detail_excludes_sql_section(): void
    {
        stopwatch()->withQueryTracking()->start();
        DB::select('SELECT 1');
        stopwatch()->checkpoint('Queried');
        stopwatch()->finish();

        $this->makeRecorder(minDurationMs: null)->record(stopwatch(), []);

        $contents = (string) file_get_contents((glob($this->tempDir . '/*.md') ?: [])[0]);

        self::assertStringNotContainsString('## SQL detail', $contents);
    }

    public function test_full_detail_appends_sql_section_without_bindings_by_default(): void
    {
        stopwatch()->withQueryTracking()->start();
        DB::select('SELECT ?', [42]);
        stopwatch()->checkpoint('Queried');
        stopwatch()->finish();

        $this->makeRecorder(minDurationMs: null, detail: 'full')->record(stopwatch(), []);

        $contents = (string) file_get_contents((glob($this->tempDir . '/*.md') ?: [])[0]);

        self::assertStringContainsString('## SQL detail', $contents);
        self::assertStringContainsString('SELECT ?', $contents);
        self::assertStringNotContainsString('Bindings', $contents);
        self::assertStringNotContainsString('[42]', $contents);
    }

    public function test_full_detail_includes_bindings_when_flag_set(): void
    {
        stopwatch()->withQueryTracking()->start();
        DB::select('SELECT ?', [42]);
        stopwatch()->checkpoint('Queried');
        stopwatch()->finish();

        $this->makeRecorder(minDurationMs: null, detail: 'full', includeBindings: true)
            ->record(stopwatch(), []);

        $contents = (string) file_get_contents((glob($this->tempDir . '/*.md') ?: [])[0]);

        self::assertStringContainsString('| Bindings |', $contents);
        self::assertStringContainsString('[42]', $contents);
    }

    public function test_full_detail_appends_http_section(): void
    {
        Http::fake();

        stopwatch()->withHttpTracking()->start();
        Http::get('https://api.example.com/orders?token=secret');
        stopwatch()->checkpoint('Called API');
        stopwatch()->finish();

        $this->makeRecorder(minDurationMs: null, detail: 'full')->record(stopwatch(), []);

        $contents = (string) file_get_contents((glob($this->tempDir . '/*.md') ?: [])[0]);

        self::assertStringContainsString('## HTTP detail', $contents);
        self::assertStringContainsString('GET', $contents);
        self::assertStringContainsString('https://api.example.com/orders', $contents);
        // Query string is stripped at capture time.
        self::assertStringNotContainsString('token=secret', $contents);
    }

    public function test_ulid_filename_unique_across_many_writes(): void
    {
        $recorder = $this->makeRecorder(minDurationMs: null);

        for ($i = 0; $i < 100; $i++) {
            $stopwatch = Stopwatch::new(clock: new FakeClock())->start();
            $stopwatch->checkpoint('Loop ' . $i);
            $stopwatch->finish();

            $recorder->record($stopwatch, []);
        }

        $files = glob($this->tempDir . '/*.md') ?: [];
        self::assertCount(100, $files);
    }

    public function test_recorder_swallows_exceptions(): void
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock())->start();
        $stopwatch->checkpoint('Step');
        $stopwatch->finish();

        // Use a path that cannot be created (parent is a file, not a directory).
        $blockedFile = sys_get_temp_dir() . '/stopwatch-blocked-' . bin2hex(random_bytes(4));
        file_put_contents($blockedFile, 'x');

        $store = new RunLogStore($blockedFile . '/cannot-be-dir');
        $recorder = new MarkdownRunRecorder(store: $store, minDurationMs: null);

        // Must not throw.
        $recorder->record($stopwatch, []);

        @unlink($blockedFile);

        self::expectNotToPerformAssertions();
    }

    public function test_count_prune_runs_after_each_write(): void
    {
        $recorder = $this->makeRecorder(minDurationMs: null, maxFiles: 3);

        for ($i = 0; $i < 5; $i++) {
            $stopwatch = Stopwatch::new(clock: new FakeClock())->start();
            $stopwatch->checkpoint('Loop ' . $i);
            $stopwatch->finish();

            $recorder->record($stopwatch, []);
        }

        $files = glob($this->tempDir . '/*.md') ?: [];
        self::assertLessThanOrEqual(3, count($files));
    }

    private function makeRecorder(
        ?int $minDurationMs = 50,
        int $maxFiles = 200,
        int $maxAgeDays = 7,
        string $detail = 'summary',
        bool $includeBindings = false,
        bool $skipEmpty = true,
    ): MarkdownRunRecorder {
        return new MarkdownRunRecorder(
            store: $this->store,
            minDurationMs: $minDurationMs,
            maxFiles: $maxFiles,
            maxAgeDays: $maxAgeDays,
            detail: $detail,
            includeBindings: $includeBindings,
            skipEmpty: $skipEmpty,
        );
    }

    private function removeTempDir(): void
    {
        if (! is_dir($this->tempDir)) {
            return;
        }

        $files = glob($this->tempDir . '/*') ?: [];

        foreach ($files as $file) {
            @unlink($file);
        }

        @unlink($this->tempDir . '/.gitignore');
        @rmdir($this->tempDir);
    }
}
