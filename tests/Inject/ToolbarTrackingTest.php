<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\Inject;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\Tests\TestCase;

final class ToolbarTrackingTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $config = [];

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        foreach ($this->config as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    public function test_the_injected_toolbar_reports_the_query_count(): void
    {
        $this->config = [
            'stopwatch.inject.mode' => 'all',
            'stopwatch.inject.allowed_environments' => 'testing',
        ];
        $this->refreshApplication();

        $this->app->make('router')->get('/toolbar-queries', static function (): string {
            DB::select('SELECT 1');
            stopwatch()->checkpoint('Query');

            return '<html><body>hi</body></html>';
        });

        $content = (string) $this->get('/toolbar-queries')->getContent();

        self::assertStringContainsString('<!--stopwatch-toolbar-->', $content);
        self::assertMatchesRegularExpression('/&#128451;\s*1q/', $content);
    }

    public function test_an_active_toolbar_turns_the_trackers_on(): void
    {
        $this->config = [
            'stopwatch.inject.mode' => 'all',
            'stopwatch.inject.allowed_environments' => 'testing',
        ];
        $this->refreshApplication();

        $checkpoint = $this->firstCheckpoint();

        self::assertIsInt($checkpoint['queryCount']);
        self::assertIsInt($checkpoint['memoryDelta']);
        self::assertIsInt($checkpoint['httpCount']);
    }

    public function test_the_trackers_stay_off_while_the_toolbar_is_off(): void
    {
        $checkpoint = $this->firstCheckpoint();

        self::assertNull($checkpoint['queryCount']);
        self::assertNull($checkpoint['memoryDelta']);
        self::assertNull($checkpoint['httpCount']);
    }

    public function test_track_false_keeps_the_trackers_off(): void
    {
        $this->config = [
            'stopwatch.inject.mode' => 'all',
            'stopwatch.inject.allowed_environments' => 'testing',
            'stopwatch.inject.track' => false,
        ];
        $this->refreshApplication();

        $checkpoint = $this->firstCheckpoint();

        self::assertNull($checkpoint['queryCount']);
        self::assertNull($checkpoint['memoryDelta']);
    }

    public function test_the_toolbar_does_not_override_an_explicit_tracking_choice(): void
    {
        $this->config = [
            'stopwatch.inject.mode' => 'all',
            'stopwatch.inject.allowed_environments' => 'testing',
            'stopwatch.inject.track' => false,
            'stopwatch.track_memory' => true,
        ];
        $this->refreshApplication();

        $checkpoint = $this->firstCheckpoint();

        self::assertIsInt($checkpoint['memoryDelta']);
        self::assertNull($checkpoint['queryCount']);
    }

    /** @return array<string, mixed> */
    private function firstCheckpoint(): array
    {
        $stopwatch = $this->app->make(Stopwatch::class);
        $stopwatch->start();
        $stopwatch->checkpoint('First');

        /** @var array<string, mixed> $checkpoint */
        $checkpoint = $stopwatch->toArray()['checkpoints'][0];

        return $checkpoint;
    }
}
