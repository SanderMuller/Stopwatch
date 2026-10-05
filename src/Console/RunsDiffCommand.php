<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Console;

use Illuminate\Console\Command;
use SanderMuller\Stopwatch\RunLog\DebugNotice;
use SanderMuller\Stopwatch\RunLog\RunDiffRenderer;
use SanderMuller\Stopwatch\RunLog\RunLogQuery;
use SanderMuller\Stopwatch\StopwatchCheckpoint;

final class RunsDiffCommand extends Command
{
    protected $signature = 'stopwatch:runs:diff
                            {a : The ULID of the first run (before), or "latest"}
                            {b : The ULID of the second run (after), or "latest"}
                            {--format=markdown : Output format — markdown | json}';

    protected $description = 'Compare two runs recorded in debug mode: timing, queries, HTTP and changed metadata per checkpoint';

    public function handle(RunLogQuery $query): int
    {
        $a = $query->resolveId($this->stringArgument('a'));
        $b = $query->resolveId($this->stringArgument('b'));

        if ($a === null || $b === null) {
            $this->components->error(DebugNotice::emptyMessage('No runs recorded yet.'));

            return self::FAILURE;
        }

        $diff = $query->diff($a, $b);

        if (is_string($diff)) {
            $this->components->error($diff);

            return self::FAILURE;
        }

        if ($this->option('format') === 'json') {
            $this->line((string) json_encode($diff, StopwatchCheckpoint::SAFE_JSON_FLAGS | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        foreach (RunDiffRenderer::markdown($diff) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }
}
