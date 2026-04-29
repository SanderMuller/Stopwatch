<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use SanderMuller\Stopwatch\RunLog\RunLogStore;
use SanderMuller\Stopwatch\Stopwatch;

final class RunsListCommand extends Command
{
    protected $signature = 'stopwatch:runs:list
                            {--limit=30 : Maximum number of runs to display}
                            {--sort=duration : Sort by duration | recorded}
                            {--slow : Show only runs that exceeded the slow threshold}
                            {--threw : Show only runs whose request threw an exception}
                            {--exception-class= : Show only runs whose exception_class equals or ends in this name (e.g. ValidationException matches Illuminate\\Validation\\ValidationException)}
                            {--ctx=* : Filter on promoted context keys — pass key=value (repeatable; e.g. --ctx tenant_id=acme)}
                            {--format=table : Output format — table | json}';

    protected $description = 'List recorded Stopwatch runs (newest or slowest first)';

    public function handle(RunLogStore $store): int
    {
        $sortKey = $this->option('sort') === 'recorded' ? 'recorded' : 'duration_ms';
        $limit = (int) $this->option('limit');

        // Pull a generous superset before filtering so `--slow --limit=30` still returns
        // up to 30 SLOW rows, not 30 rows-then-filter (which silently drops matches that
        // sat outside the initial sort window).
        $rows = $store->listRuns(PHP_INT_MAX, $sortKey);
        $rows = array_slice($this->applyFilters($rows), 0, max(1, $limit));

        if ($this->option('format') === 'json') {
            $this->renderJson($rows);

            return self::SUCCESS;
        }

        return $this->renderTable($rows);
    }

    /**
     * @param list<array{id: string, frontmatter: array<string, scalar|null>}> $rows
     */
    private function renderTable(array $rows): int
    {
        if ($rows === []) {
            $this->components->warn('No runs recorded yet. Set STOPWATCH_LOG_RUNS=true and exercise the app to start logging.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['ID', 'Duration', 'URL / Command', 'Status', 'Recorded'],
            array_map(fn (array $row): array => [
                $row['id'],
                $this->formatDuration($row['frontmatter']['duration_ms'] ?? null),
                $this->formatTarget($row['frontmatter']),
                $this->formatStatus($row['frontmatter']),
                $this->formatRecordedAt($row['frontmatter']),
            ], $rows),
        );

        $this->newLine();
        $this->line('  <fg=gray>Run</> <fg=white>php artisan stopwatch:runs:show [id]</> <fg=gray>to inspect a run.</>');

        return self::SUCCESS;
    }

    /**
     * @param list<array{id: string, frontmatter: array<string, scalar|null>}> $rows
     */
    private function renderJson(array $rows): void
    {
        // Output one JSON document with the matched-and-sliced rows. Empty list is
        // valid (`[]`) — scripts can pipe into `jq` and rely on a parseable shape
        // even when no runs exist.
        $this->line((string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    /**
     * @param list<array{id: string, frontmatter: array<string, scalar|null>}> $rows
     * @return list<array{id: string, frontmatter: array<string, scalar|null>}>
     */
    private function applyFilters(array $rows): array
    {
        $rawCtx = $this->option('ctx');
        $exceptionClass = $this->option('exception-class');

        return RunListFilters::apply(
            rows: $rows,
            slow: $this->option('slow') === true,
            threw: $this->option('threw') === true,
            exceptionClass: is_string($exceptionClass) ? $exceptionClass : null,
            ctxFilters: is_array($rawCtx) ? RunListFilters::parseCtxOption($rawCtx) : [],
        );
    }

    private function formatDuration(string|int|float|bool|null $value): string
    {
        if (! is_numeric($value)) {
            return '-';
        }

        return Stopwatch::formatDuration((float) $value);
    }

    /**
     * @param array<string, scalar|null> $frontmatter
     */
    private function formatTarget(array $frontmatter): string
    {
        $base = $this->formatRequestOrCommand($frontmatter);
        $exceptionClass = $frontmatter['exception_class'] ?? null;

        if (is_string($exceptionClass) && $exceptionClass !== '') {
            // Surface the exception class for crashed runs — spec §2.1 promised the
            // class is reachable from list view without re-parsing the body.
            return $base . ' · ' . $this->shortenExceptionClass($exceptionClass);
        }

        return $base;
    }

    /**
     * @param array<string, scalar|null> $frontmatter
     */
    private function formatRequestOrCommand(array $frontmatter): string
    {
        if (is_string($frontmatter['command'] ?? null) && $frontmatter['command'] !== '') {
            return 'artisan ' . $frontmatter['command'];
        }

        $method = is_string($frontmatter['method'] ?? null) ? $frontmatter['method'] . ' ' : '';
        $url = is_string($frontmatter['url'] ?? null) ? $frontmatter['url'] : '-';

        return $method . $url;
    }

    /**
     * Trim `Foo\Bar\BazException` to `BazException` so the table column doesn't
     * blow out on FQCNs. Full class is still available via `stopwatch:runs:show`.
     */
    private function shortenExceptionClass(string $class): string
    {
        $lastSeparator = strrpos($class, '\\');

        return $lastSeparator === false ? $class : substr($class, $lastSeparator + 1);
    }

    /**
     * @param array<string, scalar|null> $frontmatter
     */
    private function formatStatus(array $frontmatter): string
    {
        $status = $frontmatter['status'] ?? null;
        $status = is_numeric($status) ? (string) (int) $status : '-';

        if (($frontmatter['threw'] ?? false) === true) {
            $status .= ' (threw)';
        }

        return $status;
    }

    /**
     * @param array<string, scalar|null> $frontmatter
     */
    private function formatRecordedAt(array $frontmatter): string
    {
        if (! is_string($frontmatter['recorded_at'] ?? null)) {
            return '-';
        }

        try {
            return Carbon::parse($frontmatter['recorded_at'])->diffForHumans(short: true);
        } catch (\Throwable) {
            return $frontmatter['recorded_at'];
        }
    }
}
