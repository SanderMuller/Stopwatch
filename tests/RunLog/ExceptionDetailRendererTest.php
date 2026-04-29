<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\RunLog;

use Exception;
use RuntimeException;
use SanderMuller\Stopwatch\RunLog\ExceptionDetail;
use SanderMuller\Stopwatch\RunLog\ExceptionDetailRenderer;
use SanderMuller\Stopwatch\Tests\TestCase;

final class ExceptionDetailRendererTest extends TestCase
{
    public function test_renders_class_file_and_line_bullets(): void
    {
        $exception = new RuntimeException('boom');
        $output = $this->renderer()->render($exception);

        self::assertStringStartsWith('## Exception', $output);
        self::assertStringContainsString('- **Class:** `RuntimeException`', $output);
        self::assertStringContainsString('- **File:**', $output);
    }

    public function test_omits_message_bullet_when_message_disabled(): void
    {
        $exception = new RuntimeException('keep this private');
        $output = $this->renderer(messageEnabled: false)->render($exception);

        self::assertStringNotContainsString('- **Message:**', $output);
        self::assertStringNotContainsString('keep this private', $output);
    }

    public function test_includes_message_bullet_when_message_enabled(): void
    {
        $exception = new RuntimeException('shareable');
        $output = $this->renderer(messageEnabled: true)->render($exception);

        self::assertStringContainsString('- **Message:** shareable', $output);
    }

    public function test_renders_trace_table_with_top_n_count_in_heading(): void
    {
        $exception = new RuntimeException('boom');
        $output = $this->renderer(traceFrames: 3)->render($exception);

        self::assertMatchesRegularExpression('/### Trace \(top \d+\)/', $output);
        self::assertStringContainsString('| # | File | Line | Call |', $output);
    }

    public function test_omits_trace_section_when_frames_zero(): void
    {
        $exception = new RuntimeException('boom');
        $output = $this->renderer(traceFrames: 0)->render($exception);

        self::assertStringNotContainsString('### Trace', $output);
    }

    public function test_renders_class_method_call_format(): void
    {
        $exception = new RuntimeException('boom');
        $output = $this->renderer(traceFrames: 20)->render($exception);

        // The renderer should produce `Class::method()` for instance method frames.
        self::assertMatchesRegularExpression(
            '/`[A-Za-z_\\\\]+::[A-Za-z_]+\(\)`/',
            $output,
            'expected at least one Class::method() call cell',
        );
    }

    public function test_renders_top_level_function_call_without_class(): void
    {
        // Build an ExceptionDetail directly so we can assert on synthetic frames.
        $detail = $this->makeRendererForFrames([
            ['file' => '/var/x.php', 'line' => 1, 'function' => 'top_level_helper'],
        ]);

        self::assertStringContainsString('`top_level_helper()`', $detail);
        self::assertStringNotContainsString('::top_level_helper', $detail);
    }

    public function test_renders_closure_call_format(): void
    {
        $detail = $this->makeRendererForFrames([
            ['file' => '/var/x.php', 'line' => 1, 'function' => '{closure}'],
        ]);

        self::assertStringContainsString('`{closure}()`', $detail);
    }

    public function test_renders_previous_subsection_when_chain_present(): void
    {
        $previous = new RuntimeException('underlying cause');
        $outer = new Exception('wrapper', 0, $previous);

        $output = $this->renderer(messageEnabled: true)->render($outer);

        self::assertStringContainsString('### Previous', $output);
        self::assertStringContainsString('- **Class:** `RuntimeException`', $output);
        self::assertStringContainsString('underlying cause', $output);
    }

    public function test_omits_previous_subsection_when_no_chain(): void
    {
        $exception = new RuntimeException('lone');
        $output = $this->renderer()->render($exception);

        self::assertStringNotContainsString('### Previous', $output);
    }

    public function test_pipe_in_message_escaped_to_avoid_breaking_table(): void
    {
        // Messages render in bullets (not table cells), but the inline escape still
        // collapses newlines so a multi-line message can't blow up the markdown layout.
        $exception = new RuntimeException("multi\nline\nmessage");
        $output = $this->renderer(messageEnabled: true)->render($exception);

        self::assertStringContainsString('multi line message', $output);
        // No raw newline inside the bullet line.
        self::assertStringNotContainsString("- **Message:** multi\n", $output);
    }

    private function renderer(
        bool $messageEnabled = false,
        int $messageMaxChars = 500,
        int $traceFrames = 10,
    ): ExceptionDetailRenderer {
        return new ExceptionDetailRenderer(new ExceptionDetail(
            messageEnabled: $messageEnabled,
            messageMaxChars: $messageMaxChars,
            traceFrames: $traceFrames,
        ));
    }

    /**
     * @param list<array{file?: string, line?: int, class?: string, function?: string, type?: string}> $frames
     */
    private function makeRendererForFrames(array $frames): string
    {
        // Bypass the Throwable builder — Exception::getTrace() is `final` and cannot be
        // mocked, so we render directly from a synthetic ExceptionData payload.
        return $this->renderer()->renderData([
            'class' => 'SyntheticException',
            'file' => 'app/Synthetic.php',
            'line' => 1,
            'frames' => $frames,
        ]);
    }
}
