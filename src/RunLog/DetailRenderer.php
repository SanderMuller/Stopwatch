<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\StopwatchCheckpoint;

/**
 * Renders the optional `## SQL detail` and `## HTTP detail` markdown sections
 * appended after `Stopwatch::toMarkdown()` when run-log detail level is `full`.
 *
 * SQL bindings are included only when {@see $includeBindings} is true — they
 * are an explicit PII opt-in.
 */
final readonly class DetailRenderer
{
    public function __construct(
        private bool $includeBindings,
    ) {}

    /**
     * @param iterable<StopwatchCheckpoint> $checkpoints
     */
    public function render(iterable $checkpoints): string
    {
        $list = array_values([...$checkpoints]);

        $sql = $this->renderSql($list);
        $http = $this->renderHttp($list);

        return ($sql === '' ? '' : "\n\n" . $sql) . ($http === '' ? '' : "\n\n" . $http);
    }

    /**
     * @param list<StopwatchCheckpoint> $checkpoints
     */
    private function renderSql(array $checkpoints): string
    {
        $rows = [];

        foreach ($checkpoints as $idx => $checkpoint) {
            foreach ($checkpoint->queryCalls ?? [] as $call) {
                $rows[] = $this->renderSqlRow($idx + 1, $checkpoint->label, $call);
            }
        }

        if ($rows === []) {
            return '';
        }

        return $this->sqlHeader() . "\n" . implode("\n", $rows);
    }

    /**
     * @param array{sql: string, bindings: array<array-key, mixed>, durationMs: float} $call
     */
    private function renderSqlRow(int $idx, string $label, array $call): string
    {
        $labelCell = $this->escapeCell($label);
        $sqlCell = $this->escapeCell($call['sql']);
        $duration = Stopwatch::formatDuration($call['durationMs']);

        if (! $this->includeBindings) {
            return "| {$idx} | {$labelCell} | {$duration} | {$sqlCell} |";
        }

        $bindings = $this->escapeCell((string) json_encode($call['bindings'], JSON_UNESCAPED_SLASHES));

        return "| {$idx} | {$labelCell} | {$duration} | {$sqlCell} | {$bindings} |";
    }

    private function sqlHeader(): string
    {
        if (! $this->includeBindings) {
            return "## SQL detail\n\n| # | Checkpoint | Duration | SQL |\n| --- | --- | --- | --- |";
        }

        return "## SQL detail\n\n| # | Checkpoint | Duration | SQL | Bindings |\n| --- | --- | --- | --- | --- |";
    }

    /**
     * @param list<StopwatchCheckpoint> $checkpoints
     */
    private function renderHttp(array $checkpoints): string
    {
        $rows = [];

        foreach ($checkpoints as $idx => $checkpoint) {
            foreach ($checkpoint->httpCalls ?? [] as $call) {
                $rows[] = $this->renderHttpRow($idx + 1, $checkpoint->label, $call);
            }
        }

        if ($rows === []) {
            return '';
        }

        return "## HTTP detail\n\n| # | Checkpoint | Method | URL | Status | Duration |\n| --- | --- | --- | --- | --- | --- |\n" . implode("\n", $rows);
    }

    /**
     * @param array{method: string, url: string, status: int, durationMs: float} $call
     */
    private function renderHttpRow(int $idx, string $label, array $call): string
    {
        $labelCell = $this->escapeCell($label);
        $method = $this->escapeCell($call['method']);
        $url = $this->escapeCell($call['url']);
        $duration = Stopwatch::formatDuration($call['durationMs']);

        return "| {$idx} | {$labelCell} | {$method} | {$url} | {$call['status']} | {$duration} |";
    }

    private function escapeCell(string $value): string
    {
        return str_replace(['|', "\n", "\r"], ['\\|', ' ', ''], $value);
    }
}
