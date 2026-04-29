<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\RunLog;

use SanderMuller\Stopwatch\RunLog\RunLogStore;
use SanderMuller\Stopwatch\Tests\TestCase;

final class RunLogStoreTest extends TestCase
{
    private string $tempDir;

    private RunLogStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/stopwatch-runlog-test-' . bin2hex(random_bytes(6));
        $this->store = new RunLogStore($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();

        parent::tearDown();
    }

    public function test_ensure_ready_creates_directory_and_gitignore(): void
    {
        self::assertDirectoryDoesNotExist($this->tempDir);

        $this->store->ensureReady();

        self::assertDirectoryExists($this->tempDir);
        self::assertFileExists($this->tempDir . '/.gitignore');
        self::assertSame("*.md\n", file_get_contents($this->tempDir . '/.gitignore'));
    }

    public function test_write_and_get_run_round_trips_frontmatter_and_body(): void
    {
        $this->store->write('01HZTEST00000000000000000A', $this->fixture([
            'duration_ms' => 487,
            'url' => '/admin/users',
        ]));

        $run = $this->store->getRun('01HZTEST00000000000000000A');

        self::assertNotNull($run);
        self::assertSame(487, $run['frontmatter']['duration_ms']);
        self::assertSame('/admin/users', $run['frontmatter']['url']);
        self::assertStringContainsString('Stopwatch profile', $run['body']);
    }

    public function test_get_run_returns_null_for_missing_id(): void
    {
        $this->store->ensureReady();

        self::assertNull($this->store->getRun('does-not-exist'));
        self::assertNull($this->store->getRunPath('does-not-exist'));
    }

    public function test_frontmatter_parser_handles_colon_in_value(): void
    {
        $this->store->write('01HZTEST00000000000000000B', $this->fixture([
            'command' => 'cache:clear',
            'duration_ms' => 12,
        ]));

        $run = $this->store->getRun('01HZTEST00000000000000000B');

        self::assertNotNull($run);
        self::assertSame('cache:clear', $run['frontmatter']['command']);
    }

    public function test_frontmatter_parser_coerces_scalars(): void
    {
        $contents = <<<'MD'
            ---
            id: 01HZX
            duration_ms: 123
            ratio: 0.75
            slow: true
            quiet: false
            url: null
            label: hello world
            ---

            body
            MD;
        $this->store->write('01HZTEST00000000000000000C', $contents);

        $run = $this->store->getRun('01HZTEST00000000000000000C');
        self::assertNotNull($run);

        $fm = $run['frontmatter'];
        self::assertSame(123, $fm['duration_ms']);
        self::assertSame(0.75, $fm['ratio']);
        self::assertTrue($fm['slow']);
        self::assertFalse($fm['quiet']);
        self::assertNull($fm['url']);
        self::assertSame('hello world', $fm['label']);
    }

    public function test_list_runs_returns_empty_when_directory_missing(): void
    {
        self::assertSame([], $this->store->listRuns());
    }

    public function test_list_runs_sorted_by_duration_desc_by_default(): void
    {
        $this->store->write('01HZTEST00000000000000000D', $this->fixture(['duration_ms' => 10]));
        $this->store->write('01HZTEST00000000000000000E', $this->fixture(['duration_ms' => 200]));
        $this->store->write('01HZTEST00000000000000000F', $this->fixture(['duration_ms' => 50]));

        $runs = $this->store->listRuns();

        self::assertCount(3, $runs);
        self::assertSame(200, $runs[0]['frontmatter']['duration_ms']);
        self::assertSame(50, $runs[1]['frontmatter']['duration_ms']);
        self::assertSame(10, $runs[2]['frontmatter']['duration_ms']);
    }

    public function test_list_runs_sorted_by_recorded_uses_ulid_order(): void
    {
        $this->store->write('01HZAA0000000000000000000A', $this->fixture(['duration_ms' => 200]));
        $this->store->write('01HZBB0000000000000000000A', $this->fixture(['duration_ms' => 10]));
        $this->store->write('01HZCC0000000000000000000A', $this->fixture(['duration_ms' => 50]));

        $runs = $this->store->listRuns(sortBy: 'recorded', descending: true);

        self::assertSame('01HZCC0000000000000000000A', $runs[0]['id']);
        self::assertSame('01HZBB0000000000000000000A', $runs[1]['id']);
        self::assertSame('01HZAA0000000000000000000A', $runs[2]['id']);
    }

    public function test_list_runs_respects_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->store->write('01HZTEST000000000000000000' . $i, $this->fixture(['duration_ms' => $i]));
        }

        self::assertCount(2, $this->store->listRuns(max: 2));
    }

    public function test_clear_deletes_all_files_and_returns_count(): void
    {
        $this->store->write('01HZTEST00000000000000000G', $this->fixture(['duration_ms' => 1]));
        $this->store->write('01HZTEST00000000000000000H', $this->fixture(['duration_ms' => 2]));

        $deleted = $this->store->clear();

        self::assertSame(2, $deleted);
        self::assertSame([], glob($this->tempDir . '/*.md') ?: []);
    }

    public function test_prune_by_count_keeps_most_recent_n(): void
    {
        $this->store->write('01HZAA0000000000000000000A', $this->fixture(['duration_ms' => 1]));
        $this->store->write('01HZBB0000000000000000000A', $this->fixture(['duration_ms' => 2]));
        $this->store->write('01HZCC0000000000000000000A', $this->fixture(['duration_ms' => 3]));
        $this->store->write('01HZDD0000000000000000000A', $this->fixture(['duration_ms' => 4]));

        $deleted = $this->store->pruneByCount(2);

        self::assertSame(2, $deleted);
        self::assertNotNull($this->store->getRunPath('01HZCC0000000000000000000A'));
        self::assertNotNull($this->store->getRunPath('01HZDD0000000000000000000A'));
        self::assertNull($this->store->getRunPath('01HZAA0000000000000000000A'));
        self::assertNull($this->store->getRunPath('01HZBB0000000000000000000A'));
    }

    public function test_prune_by_count_no_op_when_under_cap(): void
    {
        $this->store->write('01HZAA0000000000000000000A', $this->fixture(['duration_ms' => 1]));

        self::assertSame(0, $this->store->pruneByCount(10));
        self::assertNotNull($this->store->getRunPath('01HZAA0000000000000000000A'));
    }

    public function test_prune_by_age_deletes_files_older_than_threshold(): void
    {
        $oldId = $this->ulidAt((time() - (10 * 86400)) * 1000);
        $newId = $this->ulidAt(time() * 1000);

        $this->store->write($oldId, $this->fixture(['duration_ms' => 1]));
        $this->store->write($newId, $this->fixture(['duration_ms' => 2]));

        $deleted = $this->store->pruneByAge(7);

        self::assertSame(1, $deleted);
        self::assertNull($this->store->getRunPath($oldId));
        self::assertNotNull($this->store->getRunPath($newId));
    }

    public function test_prune_by_age_respects_the_threshold_parameter(): void
    {
        $fiveDayId = $this->ulidAt((time() - (5 * 86400)) * 1000);
        $this->store->write($fiveDayId, $this->fixture(['duration_ms' => 1]));

        // 5d-old file with a 7d threshold must NOT be pruned.
        self::assertSame(0, $this->store->pruneByAge(7));
        self::assertNotNull($this->store->getRunPath($fiveDayId));

        // Same file with a 3d threshold MUST be pruned.
        self::assertSame(1, $this->store->pruneByAge(3));
        self::assertNull($this->store->getRunPath($fiveDayId));
    }

    public function test_list_runs_reads_frontmatter_larger_than_4kb(): void
    {
        // Build a frontmatter that's between the old 4096-byte and new 8192-byte read window
        // so we exercise the bumped reader limit. ~6KB total, all valid `key: value` lines.
        $lines = ['---', 'duration_ms: 487'];
        $padding = str_repeat('x', 80);  // 80 chars/line × ~70 lines ≈ 5600 bytes of frontmatter
        for ($i = 0; $i < 70; $i++) {
            $lines[] = "ctx_pad_{$i}: {$padding}";
        }
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '# Stopwatch profile';
        $lines[] = 'body';

        $contents = implode("\n", $lines);
        // Sanity: the frontmatter close fence must sit beyond the OLD 4096-byte window
        // for this regression test to actually exercise the bump.
        $closingAt = strpos($contents, "\n---\n");
        self::assertNotFalse($closingAt);
        self::assertGreaterThan(4096, $closingAt, 'fixture must straddle the old read limit');
        self::assertLessThan(8192, $closingAt, 'fixture must fit inside the new read limit');

        $this->store->write('01HZBIG0000000000000000000', $contents);

        $runs = $this->store->listRuns();

        self::assertCount(1, $runs);
        self::assertSame(487, $runs[0]['frontmatter']['duration_ms']);
        self::assertSame($padding, $runs[0]['frontmatter']['ctx_pad_69']);
    }

    public function test_list_runs_io_is_bounded_to_frontmatter_window_at_scale(): void
    {
        // 200 files with a small frontmatter (~200 bytes) but a huge body (~32 KB) that
        // contains decoy `body_marker: should_not_appear` lines past the closing fence.
        // If the reader read past the frontmatter close, those decoy keys would be
        // parsed as frontmatter pairs. Listing must NEVER pick up `body_marker`.
        $bodyDecoy = str_repeat("body_marker: should_not_appear\n", 1024);  // ~32 KB
        $count = 200;

        for ($i = 0; $i < $count; $i++) {
            $this->store->write(
                $this->ulidAt(time() * 1000 - $i),
                $this->fixture(['duration_ms' => $i, 'url' => "/path/{$i}"]) . "\n" . $bodyDecoy,
            );
        }

        $startedAt = hrtime(true);
        $runs = $this->store->listRuns(max: 30);
        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

        self::assertCount(30, $runs);
        foreach ($runs as $row) {
            self::assertArrayNotHasKey('body_marker', $row['frontmatter']);
        }

        // Listing 200 files at 32 KB each totals ~6 MB on disk. Bounded I/O reads at
        // most ~8 KB × 200 = 1.6 MB, so well under a second on any modern dev machine.
        // Generous 5s budget guards against truly broken bounding without flaking.
        self::assertLessThan(5000, $elapsedMs, 'listRuns should be bounded by the frontmatter read window, not by total file size');
    }

    public function test_prune_by_age_falls_back_to_mtime_for_non_ulid_filenames(): void
    {
        // Filename intentionally not 26 chars — exercises the mtime fallback path.
        $this->store->write('legacy-file', $this->fixture(['duration_ms' => 1]));
        touch($this->tempDir . '/legacy-file.md', time() - (10 * 86400));

        self::assertSame(1, $this->store->pruneByAge(7));
        self::assertNull($this->store->getRunPath('legacy-file'));
    }

    public function test_prune_by_age_no_op_when_zero_or_negative(): void
    {
        $this->store->write('01HZAA0000000000000000000A', $this->fixture(['duration_ms' => 1]));

        self::assertSame(0, $this->store->pruneByAge(0));
        self::assertSame(0, $this->store->pruneByAge(-1));
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    private function fixture(array $overrides = []): string
    {
        $defaults = [
            'id' => '01HZFIXTURE0000000000000000',
            'duration_ms' => 100,
            'checkpoints' => 1,
            'url' => '/test',
            'method' => 'GET',
            'status' => 200,
            'command' => null,
        ];

        $merged = array_merge($defaults, $overrides);

        $lines = ['---'];

        foreach ($merged as $key => $value) {
            $lines[] = $key . ': ' . match (true) {
                $value === null => 'null',
                $value === true => 'true',
                $value === false => 'false',
                default => (string) $value,
            };
        }

        $lines[] = '---';

        return implode("\n", $lines) . "\n\n# Stopwatch profile\n\nbody contents\n";
    }

    /**
     * Build a 26-char Crockford-base32 ULID whose timestamp prefix encodes the given
     * millisecond UTC timestamp. The 16-char entropy tail is fixed at zeros — sufficient
     * for these tests because uniqueness is provided by the caller-chosen timestamp.
     */
    private function ulidAt(int $msTimestamp): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $head = '';

        for ($i = 9; $i >= 0; $i--) {
            $head = $alphabet[$msTimestamp & 31] . $head;
            $msTimestamp >>= 5;
        }

        return $head . str_repeat('0', 16);
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
