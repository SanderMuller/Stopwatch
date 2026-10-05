<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\Mcp;

use Illuminate\Support\Facades\File;
use Laravel\Boost\BoostServiceProvider;
use Laravel\Boost\Mcp\Boost;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Mcp\Server\Registrar;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use ReflectionMethod;
use SanderMuller\Stopwatch\Mcp\McpRegistrar;
use SanderMuller\Stopwatch\Mcp\StopwatchServer;
use SanderMuller\Stopwatch\Mcp\Tools\ClearRunsTool;
use SanderMuller\Stopwatch\Mcp\Tools\DiffRunsTool;
use SanderMuller\Stopwatch\Mcp\Tools\ListRunsTool;
use SanderMuller\Stopwatch\Mcp\Tools\ShowRunTool;
use SanderMuller\Stopwatch\RunLog\RunLogStore;
use SanderMuller\Stopwatch\Tests\TestCase;

final class McpToolsTest extends TestCase
{
    private const string OLD = '01HZAA0000000000000000000A';

    private const string NEW = '01HZBB0000000000000000000A';

    private string $dir;

    protected function getPackageProviders($app): array
    {
        return [McpServiceProvider::class, BoostServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/stopwatch-mcp-' . uniqid();
        config()->set('stopwatch.run_log.path', $this->dir);
        $this->app->forgetInstance(RunLogStore::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_the_stopwatch_server_is_registered_as_a_local_handle(): void
    {
        self::assertNotNull($this->app->make(Registrar::class)->getLocalServer('stopwatch'));
    }

    public function test_boost_lists_the_tools_next_to_its_own(): void
    {
        $tools = (new ReflectionMethod(Boost::class, 'discoverTools'))->invoke(new Boost(new FakeTransporter()));
        $names = array_map(static fn (string|object $tool): string => is_string($tool) ? $tool : $tool::class, $tools);

        foreach (McpRegistrar::TOOLS as $tool) {
            self::assertContains($tool, $names);
        }
    }

    public function test_existing_boost_includes_are_kept(): void
    {
        config()->set('boost.mcp.tools.include', ['App\\Mcp\\HostTool']);

        McpRegistrar::register($this->app);

        $included = config('boost.mcp.tools.include');
        self::assertIsArray($included);
        self::assertSame('App\\Mcp\\HostTool', $included[0]);
        self::assertCount(1 + count(McpRegistrar::TOOLS), $included);
    }

    public function test_nothing_is_registered_without_laravel_mcp(): void
    {
        config()->set('boost.mcp.tools.include', []);
        $this->app->forgetInstance(Registrar::class);
        $this->app->offsetUnset(Registrar::class);

        McpRegistrar::register($this->app, static fn (string $class): bool => false);

        self::assertFalse($this->app->bound(Registrar::class));
        self::assertSame([], config('boost.mcp.tools.include'));
    }

    public function test_list_runs_returns_rows_with_state(): void
    {
        $this->stream(self::OLD, end: 'shutdown');
        $this->stream(self::NEW, end: null);

        StopwatchServer::tool(ListRunsTool::class, ['limit' => 5])
            ->assertOk()
            ->assertSee([self::NEW, '"state": "unfinished"', self::OLD, '"state": "shutdown"']);
    }

    public function test_list_runs_without_runs_says_so(): void
    {
        StopwatchServer::tool(ListRunsTool::class, [])->assertOk()->assertSee('No runs recorded yet.');
    }

    public function test_show_run_defaults_to_latest_markdown(): void
    {
        $this->stream(self::OLD, end: 'finished');
        $this->stream(self::NEW, end: null);

        StopwatchServer::tool(ShowRunTool::class, [])
            ->assertOk()
            ->assertSee(['# Stopwatch run (unfinished)', self::NEW, 'app/Probe.php:12']);
    }

    public function test_show_run_json_and_missing_stream(): void
    {
        $this->stream(self::NEW, end: 'finished');
        $this->app->make(RunLogStore::class)->write(self::OLD, "---\nid: " . self::OLD . "\nduration_ms: 1\n---\n");

        StopwatchServer::tool(ShowRunTool::class, ['id' => self::NEW, 'format' => 'json'])
            ->assertOk()
            ->assertSee('"type": "checkpoint"');
        StopwatchServer::tool(ShowRunTool::class, ['id' => self::OLD, 'format' => 'json'])
            ->assertHasErrors(['has no JSONL stream']);
        StopwatchServer::tool(ShowRunTool::class, ['id' => 'nope'])
            ->assertHasErrors(['Run [nope] not found.']);
    }

    public function test_diff_runs_compares_two_streams(): void
    {
        $this->stream(self::OLD, end: 'finished', userId: 41);
        $this->stream(self::NEW, end: 'finished', userId: 42);

        StopwatchServer::tool(DiffRunsTool::class, ['a' => self::OLD, 'b' => 'latest'])
            ->assertOk()
            ->assertSee(['"label": "Probe"', '"a": "41"', '"b": "42"']);
    }

    public function test_show_run_refuses_ids_that_leave_the_runs_directory(): void
    {
        mkdir($this->dir . '/nested', 0755, true);
        file_put_contents($this->dir . '/secret.md', "---\nid: secret\n---\nsecret contents\n");

        StopwatchServer::tool(ShowRunTool::class, ['id' => 'nested/../secret'])
            ->assertHasErrors(['Run [nested/../secret] not found.']);
    }

    public function test_clear_runs_needs_confirm(): void
    {
        $this->stream(self::OLD, end: 'finished');

        StopwatchServer::tool(ClearRunsTool::class, [])->assertHasErrors(['Nothing deleted.']);
        self::assertFileExists($this->dir . '/' . self::OLD . '.jsonl');

        StopwatchServer::tool(ClearRunsTool::class, ['confirm' => true])->assertOk()->assertSee('Deleted 1 run(s).');
        self::assertFileDoesNotExist($this->dir . '/' . self::OLD . '.jsonl');
    }

    public function test_tools_run_when_called_like_boost_does(): void
    {
        // Boost's ExecuteToolCommand calls $tool->handle($request) directly, without
        // container method injection.
        $this->stream(self::NEW, end: 'finished');

        foreach ([
            ListRunsTool::class => [],
            ShowRunTool::class => ['id' => 'latest'],
            DiffRunsTool::class => ['a' => self::NEW, 'b' => self::NEW],
            ClearRunsTool::class => [],
        ] as $tool => $arguments) {
            $response = $this->app->make($tool)->handle(new Request($arguments));

            self::assertInstanceOf(Response::class, $response, $tool);
        }
    }

    public function test_clear_runs_only_deletes_on_boolean_true(): void
    {
        $this->stream(self::OLD, end: 'finished');

        foreach ([false, 'true', 1] as $confirm) {
            StopwatchServer::tool(ClearRunsTool::class, ['confirm' => $confirm])->assertHasErrors(['Nothing deleted.']);
        }

        self::assertFileExists($this->dir . '/' . self::OLD . '.jsonl');
    }

    public function test_list_runs_returns_the_newest_first_within_the_limit(): void
    {
        $this->stream(self::OLD, end: 'finished');
        $this->stream(self::NEW, end: 'finished');

        StopwatchServer::tool(ListRunsTool::class, ['limit' => 1])
            ->assertOk()
            ->assertSee(self::NEW)
            ->assertDontSee(self::OLD);
    }

    public function test_list_runs_filters_on_threw(): void
    {
        $this->stream(self::NEW, end: 'finished');
        $this->app->make(RunLogStore::class)->write(self::OLD, "---\nid: " . self::OLD . "\nthrew: true\n---\n");

        StopwatchServer::tool(ListRunsTool::class, ['threw' => true])
            ->assertOk()
            ->assertSee(self::OLD)
            ->assertDontSee(self::NEW);
    }

    private function stream(string $id, ?string $end, int $userId = 42): void
    {
        $store = $this->app->make(RunLogStore::class);
        $store->appendLine($id, (string) json_encode(['type' => 'start', 'run' => $id, 'at' => '2026-10-05T12:00:00.000+00:00', 'url' => '/stream', 'method' => 'GET']));
        $store->appendLine($id, (string) json_encode(['type' => 'checkpoint', 'i' => 1, 'label' => 'Probe', 'probe' => true, 'location' => 'app/Probe.php:12', 'delta_ms' => 1.5, 'total_ms' => 1.5, 'metadata' => ['user' => $userId], 'queries' => null, 'http' => null]));

        if ($end !== null) {
            $store->appendLine($id, (string) json_encode(['type' => 'end', 'reason' => $end, 'duration_ms' => 2.0, 'checkpoints' => 1, 'dropped' => 0]));
        }
    }
}
