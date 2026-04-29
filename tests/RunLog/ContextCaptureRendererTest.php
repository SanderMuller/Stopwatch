<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\RunLog;

use SanderMuller\Stopwatch\RunLog\ContextCaptureRenderer;
use SanderMuller\Stopwatch\Tests\TestCase;

final class ContextCaptureRendererTest extends TestCase
{
    public function test_empty_body_yields_empty_string(): void
    {
        // Recorder uses this signal to skip appending the `## Context` section entirely.
        self::assertSame('', (new ContextCaptureRenderer())->render([]));
    }

    public function test_renders_two_column_table_with_section_heading(): void
    {
        $output = (new ContextCaptureRenderer())->render([
            'trace_id' => '01HZULID',
            'tenant_id' => 'acme',
        ]);

        self::assertStringStartsWith('## Context', $output);
        self::assertStringContainsString('| Key | Value |', $output);
        self::assertStringContainsString('| --- | --- |', $output);
        self::assertStringContainsString('| `trace_id` | 01HZULID |', $output);
        self::assertStringContainsString('| `tenant_id` | acme |', $output);
    }

    public function test_escapes_pipes_in_values_to_avoid_breaking_table(): void
    {
        $output = (new ContextCaptureRenderer())->render([
            'piped' => 'a|b|c',
        ]);

        self::assertStringContainsString('| `piped` | a\\|b\\|c |', $output);
    }

    public function test_collapses_newlines_in_values(): void
    {
        $output = (new ContextCaptureRenderer())->render([
            'multiline' => "first\nsecond",
        ]);

        self::assertStringContainsString('| `multiline` | first second |', $output);
        self::assertStringNotContainsString("first\nsecond", $output);
    }

    public function test_escapes_pipes_in_keys(): void
    {
        $output = (new ContextCaptureRenderer())->render([
            'a|b' => 'value',
        ]);

        // Pipe in the key column would also break the table layout.
        self::assertStringContainsString('| `a\\|b` | value |', $output);
    }
}
