<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\RunLog;

use Illuminate\Support\Facades\Context;
use RuntimeException;
use SanderMuller\Stopwatch\FakeClock;
use SanderMuller\Stopwatch\RunLog\ContextCapture;
use SanderMuller\Stopwatch\RunLog\ContextCaptureRenderer;
use SanderMuller\Stopwatch\RunLog\ExceptionDetail;
use SanderMuller\Stopwatch\RunLog\ExceptionDetailRenderer;
use SanderMuller\Stopwatch\RunLog\MarkdownRunRecorder;
use SanderMuller\Stopwatch\RunLog\RunLogStore;
use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\Tests\TestCase;

/**
 * MarkdownRunRecorder integration tests for the Phase 5 exception + Context collectors.
 * The original `MarkdownRunRecorderTest` covers the existing summary/full/SQL/HTTP paths;
 * this class adds the new collector behaviour without disturbing those.
 */
final class MarkdownRunRecorderExceptionContextTest extends TestCase
{
    private string $tempDir;

    private RunLogStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        Context::flush();

        $this->tempDir = sys_get_temp_dir() . '/stopwatch-recorder-ec-' . bin2hex(random_bytes(6));
        $this->store = new RunLogStore($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();

        parent::tearDown();
    }

    public function test_exception_persisted_when_transient_context_has_it(): void
    {
        $stopwatch = $this->makeStopwatch();
        $stopwatch->recordRunsTo($this->makeRecorder(messageEnabled: true));
        $stopwatch->checkpoint('Before crash');
        $stopwatch->withTransientContext(Stopwatch::TRANSIENT_EXCEPTION, new RuntimeException('boom'));
        $stopwatch->finish();

        $contents = $this->onlyFile();
        self::assertStringContainsString('exception_class: RuntimeException', $contents);
        self::assertStringContainsString('exception_line:', $contents);
        self::assertStringContainsString('## Exception', $contents);
        self::assertStringContainsString('- **Message:** boom', $contents);
    }

    public function test_no_exception_fields_when_nothing_captured(): void
    {
        $stopwatch = $this->makeStopwatch();
        $stopwatch->recordRunsTo($this->makeRecorder());
        $stopwatch->checkpoint('Step');
        $stopwatch->finish();

        $contents = $this->onlyFile();
        self::assertStringNotContainsString('exception_class', $contents);
        self::assertStringNotContainsString('## Exception', $contents);
    }

    public function test_collect_exceptions_disabled_skips_section_even_when_transient_set(): void
    {
        $stopwatch = $this->makeStopwatch();
        $stopwatch->recordRunsTo($this->makeRecorder(collectExceptions: false));
        $stopwatch->checkpoint('Step');
        $stopwatch->withTransientContext(Stopwatch::TRANSIENT_EXCEPTION, new RuntimeException('ignored'));
        $stopwatch->finish();

        $contents = $this->onlyFile();
        self::assertStringNotContainsString('exception_class', $contents);
        self::assertStringNotContainsString('## Exception', $contents);
    }

    public function test_context_section_present_when_collect_context_enabled(): void
    {
        Context::add('trace_id', '01HZULID');
        Context::add('tenant_id', 'acme');

        $stopwatch = $this->makeStopwatch();
        $stopwatch->recordRunsTo($this->makeRecorder(collectContext: true));
        $stopwatch->checkpoint('Step');
        $stopwatch->finish();

        $contents = $this->onlyFile();
        self::assertStringContainsString('## Context', $contents);
        self::assertStringContainsString('| `trace_id` | 01HZULID |', $contents);
        self::assertStringContainsString('| `tenant_id` | acme |', $contents);
    }

    public function test_context_section_omitted_when_collect_context_disabled(): void
    {
        Context::add('trace_id', '01HZULID');

        $stopwatch = $this->makeStopwatch();
        $stopwatch->recordRunsTo($this->makeRecorder(collectContext: false));
        $stopwatch->checkpoint('Step');
        $stopwatch->finish();

        $contents = $this->onlyFile();
        self::assertStringNotContainsString('## Context', $contents);
        self::assertStringNotContainsString('trace_id', $contents);
    }

    public function test_frontmatter_promotion_writes_ctx_lines(): void
    {
        Context::add('trace_id', '01HZULID');
        Context::add('tenant_id', 'acme');

        $stopwatch = $this->makeStopwatch();
        $contextCapture = new ContextCapture(frontmatterKeys: ['trace_id', 'tenant_id']);
        $stopwatch->recordRunsTo($this->makeRecorder(
            collectContext: true,
            contextCapture: $contextCapture,
        ));
        $stopwatch->checkpoint('Step');
        $stopwatch->finish();

        $contents = $this->onlyFile();
        self::assertStringContainsString('ctx_trace_id: 01HZULID', $contents);
        self::assertStringContainsString('ctx_tenant_id: acme', $contents);
    }

    public function test_promoted_string_value_round_trips_via_quoted_form(): void
    {
        // Phase 2 codec extension regression — `"01"` must round-trip as the string,
        // not the int 1.
        Context::add('user_code', '01');

        $stopwatch = $this->makeStopwatch();
        $stopwatch->recordRunsTo($this->makeRecorder(
            collectContext: true,
            contextCapture: new ContextCapture(frontmatterKeys: ['user_code']),
        ));
        $stopwatch->checkpoint('Step');
        $stopwatch->finish();

        $contents = $this->onlyFile();
        self::assertStringContainsString("ctx_user_code: '01'", $contents);

        // The reader decodes the quoted form back to the original string.
        $files = glob($this->tempDir . '/*.md') ?: [];
        $id = pathinfo((string) $files[0], PATHINFO_FILENAME);
        $run = $this->store->getRun($id);
        self::assertSame('01', $run['frontmatter']['ctx_user_code'] ?? null);
    }

    public function test_section_ordering_is_stable(): void
    {
        Context::add('trace_id', '01HZULID');

        $stopwatch = $this->makeStopwatch();
        $stopwatch->recordRunsTo($this->makeRecorder(
            collectExceptions: true,
            collectContext: true,
        ));
        $stopwatch->checkpoint('Step');
        $stopwatch->withTransientContext(Stopwatch::TRANSIENT_EXCEPTION, new RuntimeException('boom'));
        $stopwatch->finish();

        $contents = $this->onlyFile();

        $stopwatchPos = strpos($contents, '# Stopwatch profile');
        $exceptionPos = strpos($contents, '## Exception');
        $contextPos = strpos($contents, '## Context');

        self::assertNotFalse($stopwatchPos);
        self::assertNotFalse($exceptionPos);
        self::assertNotFalse($contextPos);
        self::assertLessThan($exceptionPos, $stopwatchPos, 'Stopwatch profile must come before ## Exception');
        self::assertLessThan($contextPos, $exceptionPos, '## Exception must come before ## Context');
    }

    public function test_default_constructor_flags_keep_exception_on_context_off(): void
    {
        Context::add('trace_id', '01HZULID');

        $stopwatch = $this->makeStopwatch();
        // Default ctor: collectExceptions=true, collectContext=false (per spec §4.1).
        $stopwatch->recordRunsTo($this->makeRecorder());
        $stopwatch->checkpoint('Step');
        $stopwatch->withTransientContext(Stopwatch::TRANSIENT_EXCEPTION, new RuntimeException('hidden'));
        $stopwatch->finish();

        $contents = $this->onlyFile();
        self::assertStringContainsString('exception_class: RuntimeException', $contents);
        self::assertStringNotContainsString('## Context', $contents);
    }

    public function test_both_collectors_off_yields_no_extra_sections(): void
    {
        Context::add('trace_id', '01HZULID');

        $stopwatch = $this->makeStopwatch();
        $stopwatch->recordRunsTo($this->makeRecorder(
            collectExceptions: false,
            collectContext: false,
        ));
        $stopwatch->checkpoint('Step');
        $stopwatch->withTransientContext(Stopwatch::TRANSIENT_EXCEPTION, new RuntimeException('hidden'));
        $stopwatch->finish();

        $contents = $this->onlyFile();
        self::assertStringNotContainsString('## Exception', $contents);
        self::assertStringNotContainsString('## Context', $contents);
        self::assertStringNotContainsString('exception_class', $contents);
    }

    public function test_empty_filtered_context_omits_section(): void
    {
        // collect_context=true but Context bag is empty post-filter → no `## Context` section.
        $stopwatch = $this->makeStopwatch();
        $stopwatch->recordRunsTo($this->makeRecorder(collectContext: true));
        $stopwatch->checkpoint('Step');
        $stopwatch->finish();

        $contents = $this->onlyFile();
        self::assertStringNotContainsString('## Context', $contents);
    }

    private function makeStopwatch(): Stopwatch
    {
        $stopwatch = Stopwatch::new(clock: new FakeClock());
        $stopwatch->start();

        return $stopwatch;
    }

    private function makeRecorder(
        bool $collectExceptions = true,
        bool $collectContext = false,
        bool $messageEnabled = false,
        ?ContextCapture $contextCapture = null,
    ): MarkdownRunRecorder {
        $exceptionRenderer = new ExceptionDetailRenderer(
            new ExceptionDetail(messageEnabled: $messageEnabled),
        );

        return new MarkdownRunRecorder(
            store: $this->store,
            minDurationMs: null,
            skipEmpty: false,
            collectExceptions: $collectExceptions,
            exceptionRenderer: $exceptionRenderer,
            collectContext: $collectContext,
            contextCapture: $contextCapture ?? new ContextCapture(),
            contextRenderer: new ContextCaptureRenderer(),
        );
    }

    private function onlyFile(): string
    {
        $files = glob($this->tempDir . '/*.md') ?: [];
        self::assertCount(1, $files, 'expected exactly one run-log file');

        return (string) file_get_contents($files[0]);
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
