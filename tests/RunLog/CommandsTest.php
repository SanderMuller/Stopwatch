<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\RunLog;

use SanderMuller\Stopwatch\RunLog\RunLogStore;
use SanderMuller\Stopwatch\Tests\TestCase;

final class CommandsTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/stopwatch-cmd-' . bin2hex(random_bytes(6));
        config()->set('stopwatch.run_log.path', $this->tempDir);
        $this->app->forgetInstance(RunLogStore::class);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();

        parent::tearDown();
    }

    public function test_list_warns_when_no_runs_recorded(): void
    {
        $this->artisan('stopwatch:runs:list')
            ->expectsOutputToContain('No runs recorded yet')
            ->assertSuccessful();
    }

    public function test_list_shows_recorded_runs(): void
    {
        $store = $this->app->make(RunLogStore::class);
        $store->write('01HZAA0000000000000000000A', $this->fixture(['url' => '/admin/users', 'duration_ms' => 487]));
        $store->write('01HZBB0000000000000000000A', $this->fixture(['url' => '/api/products', 'duration_ms' => 120]));

        $this->artisan('stopwatch:runs:list')
            ->expectsOutputToContain('/admin/users')
            ->expectsOutputToContain('/api/products')
            ->assertSuccessful();
    }

    public function test_list_with_slow_filter_excludes_fast_runs(): void
    {
        $store = $this->app->make(RunLogStore::class);
        $store->write('01HZAA0000000000000000000A', $this->fixture(['url' => '/slow', 'exceeds_slow_threshold' => true]));
        $store->write('01HZBB0000000000000000000A', $this->fixture(['url' => '/fast', 'exceeds_slow_threshold' => false]));

        $this->artisan('stopwatch:runs:list', ['--slow' => true])
            ->expectsOutputToContain('/slow')
            ->doesntExpectOutputToContain('/fast')
            ->assertSuccessful();
    }

    public function test_list_with_threw_filter_excludes_clean_runs(): void
    {
        $store = $this->app->make(RunLogStore::class);
        $store->write('01HZAA0000000000000000000A', $this->fixture(['url' => '/crashed', 'threw' => true]));
        $store->write('01HZBB0000000000000000000A', $this->fixture(['url' => '/clean']));

        $this->artisan('stopwatch:runs:list', ['--threw' => true])
            ->expectsOutputToContain('/crashed')
            ->doesntExpectOutputToContain('/clean')
            ->assertSuccessful();
    }

    public function test_show_prints_run_contents(): void
    {
        $store = $this->app->make(RunLogStore::class);
        $store->write('01HZAA0000000000000000000A', $this->fixture(['url' => '/inspect-me']));

        $this->artisan('stopwatch:runs:show', ['id' => '01HZAA0000000000000000000A'])
            ->expectsOutputToContain('/inspect-me')
            ->expectsOutputToContain('body contents')
            ->assertSuccessful();
    }

    public function test_show_returns_failure_for_unknown_id(): void
    {
        $this->artisan('stopwatch:runs:show', ['id' => 'does-not-exist'])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    }

    public function test_clear_with_keep_drops_oldest(): void
    {
        $store = $this->app->make(RunLogStore::class);
        $store->write('01HZAA0000000000000000000A', $this->fixture());
        $store->write('01HZBB0000000000000000000A', $this->fixture());
        $store->write('01HZCC0000000000000000000A', $this->fixture());

        $this->artisan('stopwatch:runs:clear', ['--keep' => 1, '--force' => true])
            ->assertSuccessful();

        self::assertNotNull($store->getRunPath('01HZCC0000000000000000000A'));
        self::assertNull($store->getRunPath('01HZAA0000000000000000000A'));
        self::assertNull($store->getRunPath('01HZBB0000000000000000000A'));
    }

    public function test_clear_with_keep_prompts_for_confirmation(): void
    {
        $store = $this->app->make(RunLogStore::class);
        $store->write('01HZAA0000000000000000000A', $this->fixture());
        $store->write('01HZBB0000000000000000000A', $this->fixture());

        $this->artisan('stopwatch:runs:clear', ['--keep' => 1])
            ->expectsConfirmation('Keep only the most recent 1 run(s) and delete the rest?', 'no')
            ->expectsOutputToContain('Aborted')
            ->assertSuccessful();

        self::assertNotNull($store->getRunPath('01HZAA0000000000000000000A'));
        self::assertNotNull($store->getRunPath('01HZBB0000000000000000000A'));
    }

    public function test_clear_with_days_drops_old_files(): void
    {
        $store = $this->app->make(RunLogStore::class);
        $oldId = $this->ulidAt((time() - (10 * 86400)) * 1000);
        $newId = $this->ulidAt(time() * 1000);

        $store->write($oldId, $this->fixture());
        $store->write($newId, $this->fixture());

        $this->artisan('stopwatch:runs:clear', ['--days' => 7, '--force' => true])
            ->assertSuccessful();

        self::assertNull($store->getRunPath($oldId));
        self::assertNotNull($store->getRunPath($newId));
    }

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

    public function test_clear_without_args_aborts_when_user_declines_confirmation(): void
    {
        $store = $this->app->make(RunLogStore::class);
        $store->write('01HZAA0000000000000000000A', $this->fixture());

        $this->artisan('stopwatch:runs:clear')
            ->expectsConfirmation('Delete ALL recorded Stopwatch runs?', 'no')
            ->expectsOutputToContain('Aborted')
            ->assertSuccessful();

        self::assertNotNull($store->getRunPath('01HZAA0000000000000000000A'));
    }

    public function test_clear_without_args_wipes_when_user_confirms(): void
    {
        $store = $this->app->make(RunLogStore::class);
        $store->write('01HZAA0000000000000000000A', $this->fixture());
        $store->write('01HZBB0000000000000000000A', $this->fixture());

        $this->artisan('stopwatch:runs:clear')
            ->expectsConfirmation('Delete ALL recorded Stopwatch runs?', 'yes')
            ->assertSuccessful();

        self::assertNull($store->getRunPath('01HZAA0000000000000000000A'));
        self::assertNull($store->getRunPath('01HZBB0000000000000000000A'));
    }

    public function test_clear_with_force_wipes_all(): void
    {
        $store = $this->app->make(RunLogStore::class);
        $store->write('01HZAA0000000000000000000A', $this->fixture());
        $store->write('01HZBB0000000000000000000A', $this->fixture());

        $this->artisan('stopwatch:runs:clear', ['--force' => true])
            ->assertSuccessful();

        self::assertNull($store->getRunPath('01HZAA0000000000000000000A'));
        self::assertNull($store->getRunPath('01HZBB0000000000000000000A'));
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    private function fixture(array $overrides = []): string
    {
        $defaults = [
            'id' => '01HZFIXTURE0000000000000000',
            'recorded_at' => '2026-04-29T12:00:00.000+00:00',
            'duration_ms' => 100,
            'checkpoints' => 1,
            'url' => '/test',
            'method' => 'GET',
            'status' => 200,
            'command' => null,
            'exceeds_slow_threshold' => false,
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
