<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\RunLog;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Context;
use SanderMuller\Stopwatch\RunLog\ConsoleCommandContextProvider;
use SanderMuller\Stopwatch\RunLog\MarkdownRunRecorder;
use SanderMuller\Stopwatch\RunLog\RunLogStore;
use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\Tests\TestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ServiceProviderRunLogTest extends TestCase
{
    public function test_run_log_disabled_by_default(): void
    {
        $stopwatch = $this->app->make(Stopwatch::class);

        // Without enabling, recorders list stays empty — context resolution returns nothing.
        self::assertSame([], $stopwatch->resolveRunContext());
    }

    public function test_run_log_singletons_resolvable(): void
    {
        self::assertInstanceOf(RunLogStore::class, $this->app->make(RunLogStore::class));
        self::assertInstanceOf(MarkdownRunRecorder::class, $this->app->make(MarkdownRunRecorder::class));
        self::assertInstanceOf(ConsoleCommandContextProvider::class, $this->app->make(ConsoleCommandContextProvider::class));
    }

    public function test_run_log_path_defaults_to_storage_subdirectory(): void
    {
        $store = $this->app->make(RunLogStore::class);

        self::assertSame($this->app->storagePath('stopwatch/runs'), $store->path());
    }

    public function test_run_log_path_overridable_via_config(): void
    {
        $tempDir = sys_get_temp_dir() . '/stopwatch-config-' . bin2hex(random_bytes(4));

        // Force singleton rebuild by re-binding.
        $this->app->forgetInstance(RunLogStore::class);
        config()->set('stopwatch.run_log.path', $tempDir);

        $store = $this->app->make(RunLogStore::class);

        self::assertSame($tempDir, $store->path());
    }

    public function test_command_starting_provider_yields_command_context(): void
    {
        config()->set('stopwatch.run_log.enabled', true);
        config()->set('stopwatch.run_log.min_duration_ms', 0);
        $this->app->forgetInstance(Stopwatch::class);
        $this->app->forgetInstance(ConsoleCommandContextProvider::class);

        $stopwatch = $this->app->make(Stopwatch::class);

        // Simulate the artisan event the provider listens for.
        $this->app->make('events')->dispatch(new CommandStarting(
            'app:reindex',
            $this->createStub(InputInterface::class),
            $this->createStub(OutputInterface::class),
        ));

        // Even after start() → reset() the command context survives because the provider
        // is evaluated lazily at finish() time.
        $stopwatch->start();
        $stopwatch->checkpoint('Step');

        self::assertSame(['command' => 'app:reindex'], $stopwatch->resolveRunContext());
    }

    public function test_collector_defaults_match_spec(): void
    {
        // Defaults at fresh-bind: collect_exceptions=true, collect_context=false,
        // exceptions.message=false. Verified by writing a run with a transient
        // exception in scope and asserting the exception_class lands but no
        // ## Context section appears.
        $tempDir = $this->makeTempStore();

        try {
            $stopwatch = $this->app->make(Stopwatch::class);
            $recorder = $this->app->make(MarkdownRunRecorder::class);
            $stopwatch->recordRunsTo($recorder);

            $stopwatch->start();
            $stopwatch->checkpoint('Step');
            $stopwatch->withTransientContext(Stopwatch::TRANSIENT_EXCEPTION, new \RuntimeException('default boom'));
            Context::add('trace_id', 'should-not-appear');
            $stopwatch->finish();

            $contents = $this->onlyFile($tempDir);
            self::assertStringContainsString('exception_class: RuntimeException', $contents);
            self::assertStringNotContainsString('- **Message:** default boom', $contents, 'message=false default must hide the message');
            self::assertStringNotContainsString('## Context', $contents, 'collect_context=false default must skip the section');
        } finally {
            $this->cleanupTempDir($tempDir);
        }
    }

    public function test_env_overrides_flow_through_for_scalar_collector_keys(): void
    {
        // Toggle every scalar collector knob.
        $tempDir = $this->makeTempStore();
        config()->set('stopwatch.run_log.collect_exceptions', false);
        config()->set('stopwatch.run_log.collect_context', true);
        config()->set('stopwatch.run_log.options.exceptions.message', true);
        config()->set('stopwatch.run_log.options.exceptions.message_max_chars', 5);
        config()->set('stopwatch.run_log.options.exceptions.trace_frames', 0);
        config()->set('stopwatch.run_log.options.context.value_max_bytes', 32);
        $this->app->forgetInstance(MarkdownRunRecorder::class);

        try {
            $stopwatch = $this->app->make(Stopwatch::class);
            $recorder = $this->app->make(MarkdownRunRecorder::class);
            $stopwatch->recordRunsTo($recorder);

            $stopwatch->start();
            $stopwatch->checkpoint('Step');
            // Throw is captured via transient — but collect_exceptions=false → ignored.
            $stopwatch->withTransientContext(Stopwatch::TRANSIENT_EXCEPTION, new \RuntimeException('overridden'));
            Context::add('trace_id', 'visible-now');
            $stopwatch->finish();

            $contents = $this->onlyFile($tempDir);
            self::assertStringNotContainsString('exception_class', $contents, 'collect_exceptions=false override must skip the field');
            self::assertStringContainsString('## Context', $contents, 'collect_context=true override must enable the section');
            self::assertStringContainsString('| `trace_id` | visible-now |', $contents);
        } finally {
            $this->cleanupTempDir($tempDir);
        }
    }

    public function test_array_options_flow_through_from_config(): void
    {
        $tempDir = $this->makeTempStore();
        config()->set('stopwatch.run_log.collect_context', true);
        config()->set('stopwatch.run_log.options.context.allow', ['kept']);
        config()->set('stopwatch.run_log.options.context.deny', ['dropped']);
        config()->set('stopwatch.run_log.options.context.mask', ['secret']);
        config()->set('stopwatch.run_log.options.context.frontmatter_keys', ['kept']);
        $this->app->forgetInstance(MarkdownRunRecorder::class);

        try {
            $stopwatch = $this->app->make(Stopwatch::class);
            $stopwatch->recordRunsTo($this->app->make(MarkdownRunRecorder::class));

            Context::add('kept', 'visible');
            Context::add('dropped', 'gone');  // denied AFTER allow
            Context::add('secret', 'leak');    // masked
            Context::add('untracked', 'invisible'); // not in allow

            $stopwatch->start();
            $stopwatch->checkpoint('Step');
            $stopwatch->finish();

            $contents = $this->onlyFile($tempDir);
            self::assertStringContainsString('| `kept` | visible |', $contents);
            self::assertStringContainsString('ctx_kept: visible', $contents, 'frontmatter_keys array must promote');
            self::assertStringNotContainsString('dropped', $contents);
            self::assertStringNotContainsString('untracked', $contents);
            // mask doesn't help here because secret is not in allow — so it never enters the body
            // either. The deny+mask test proves the array value reached the recorder.
        } finally {
            $this->cleanupTempDir($tempDir);
        }
    }

    public function test_bogus_array_config_degrades_gracefully(): void
    {
        // A user mis-types `mask_message_matching` as a string. Recorder must still
        // construct without throwing — no crash on bad config.
        config()->set('stopwatch.run_log.options.exceptions.mask_message_matching', 'not-an-array');
        config()->set('stopwatch.run_log.options.context.allow', 42);
        config()->set('stopwatch.run_log.options.context.value_max_bytes', 'not-a-number');
        $this->app->forgetInstance(MarkdownRunRecorder::class);

        $recorder = $this->app->make(MarkdownRunRecorder::class);

        self::assertInstanceOf(MarkdownRunRecorder::class, $recorder);
    }

    private function makeTempStore(): string
    {
        $tempDir = sys_get_temp_dir() . '/stopwatch-sp-' . bin2hex(random_bytes(6));
        config()->set('stopwatch.run_log.path', $tempDir);
        config()->set('stopwatch.run_log.min_duration_ms', 0);
        config()->set('stopwatch.run_log.skip_empty', false);
        $this->app->forgetInstance(RunLogStore::class);
        $this->app->forgetInstance(MarkdownRunRecorder::class);
        Context::flush();

        return $tempDir;
    }

    private function onlyFile(string $tempDir): string
    {
        $files = glob($tempDir . '/*.md') ?: [];
        self::assertCount(1, $files, 'expected exactly one run-log file');

        return (string) file_get_contents($files[0]);
    }

    private function cleanupTempDir(string $tempDir): void
    {
        if (! is_dir($tempDir)) {
            return;
        }

        foreach (glob($tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @unlink($tempDir . '/.gitignore');
        @rmdir($tempDir);
    }
}
