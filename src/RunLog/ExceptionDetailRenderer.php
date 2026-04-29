<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

use Throwable;

/**
 * Markdown renderer for the optional `## Exception` body section appended after
 * `Stopwatch::toMarkdown()` when a captured `Throwable` is present and exception
 * collection is enabled.
 *
 * @phpstan-import-type ExceptionData from ExceptionDetail
 * @phpstan-import-type Frame from ExceptionDetail
 */
final readonly class ExceptionDetailRenderer
{
    public function __construct(
        private ExceptionDetail $builder,
    ) {}

    public function render(Throwable $exception): string
    {
        return $this->renderData($this->builder->build($exception));
    }

    /**
     * Render a pre-built {@see ExceptionData} array. Useful for callers (and tests) that
     * have constructed the data shape directly without going through a `Throwable`.
     *
     * @param ExceptionData $data
     */
    public function renderData(array $data): string
    {
        $lines = [
            '## Exception',
            '',
            '- **Class:** `' . $data['class'] . '`',
            '- **File:** `' . $data['file'] . ':' . $data['line'] . '`',
        ];

        if (isset($data['message'])) {
            $lines[] = '- **Message:** ' . $this->escapeInline($data['message']);
        }

        $traceSection = $this->renderTrace($data['frames']);

        if ($traceSection !== '') {
            $lines[] = '';
            $lines[] = $traceSection;
        }

        if (isset($data['previous'])) {
            $lines[] = '';
            $lines[] = $this->renderPrevious($data['previous']);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<Frame> $frames
     */
    private function renderTrace(array $frames): string
    {
        if ($frames === []) {
            return '';
        }

        $lines = [
            '### Trace (top ' . count($frames) . ')',
            '',
            '| # | File | Line | Call |',
            '| --- | --- | --- | --- |',
        ];

        foreach ($frames as $i => $frame) {
            $lines[] = sprintf(
                '| %d | %s | %s | `%s` |',
                $i + 1,
                $this->escapeCell($frame['file'] ?? ''),
                $frame['line'] ?? '',
                $this->escapeCell($this->formatCall($frame)),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{class: string, file: string, line: int, message?: string} $previous
     */
    private function renderPrevious(array $previous): string
    {
        $lines = [
            '### Previous',
            '',
            '- **Class:** `' . $previous['class'] . '`',
            '- **File:** `' . $previous['file'] . ':' . $previous['line'] . '`',
        ];

        if (isset($previous['message'])) {
            $lines[] = '- **Message:** ' . $this->escapeInline($previous['message']);
        }

        return implode("\n", $lines);
    }

    /**
     * @param Frame $frame
     */
    private function formatCall(array $frame): string
    {
        $function = $frame['function'] ?? 'unknown';
        $class = $frame['class'] ?? null;

        if ($class === null) {
            return $function . '()';
        }

        // Spec: render `Class::method()` regardless of the original call type
        // (`->` instance vs `::` static) — keeps the trace visually aligned and
        // privacy-equivalent. Closures already arrive as `function == '{closure}'`
        // with no class.
        return $class . '::' . $function . '()';
    }

    private function escapeCell(string $value): string
    {
        return str_replace(['|', "\n", "\r"], ['\\|', ' ', ''], $value);
    }

    private function escapeInline(string $value): string
    {
        // Bullet-list values: collapse newlines so the message stays on one bullet line.
        return str_replace(["\r", "\n"], [' ', ' '], $value);
    }
}
