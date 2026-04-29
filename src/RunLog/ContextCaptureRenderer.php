<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

/**
 * Renders the `## Context` body section as a two-column markdown table.
 *
 * Empty body → empty string. The recorder uses a `body !== []` guard to skip
 * appending the section entirely when nothing is captured (mirrors how empty
 * SQL/HTTP detail tables are handled).
 */
final readonly class ContextCaptureRenderer
{
    /**
     * @param array<string, string> $body pre-rendered string values from {@see ContextCapture}
     */
    public function render(array $body): string
    {
        if ($body === []) {
            return '';
        }

        $lines = [
            '## Context',
            '',
            '| Key | Value |',
            '| --- | --- |',
        ];

        foreach ($body as $key => $value) {
            $lines[] = '| `' . $this->escapeCell($key) . '` | ' . $this->escapeCell($value) . ' |';
        }

        return implode("\n", $lines);
    }

    private function escapeCell(string $value): string
    {
        return str_replace(['|', "\n", "\r"], ['\\|', ' ', ''], $value);
    }
}
