<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Console;

use Illuminate\Console\Command;
use SanderMuller\Stopwatch\RunLog\DebugNotice;
use SanderMuller\Stopwatch\RunLog\RunLogQuery;
use SanderMuller\Stopwatch\StopwatchCheckpoint;

final class RunsShowCommand extends Command
{
    protected $signature = 'stopwatch:runs:show
                            {id : The ULID of the run to inspect, or "latest" for the newest run}
                            {--format=markdown : Output format — markdown | json (the JSONL debug stream records)}';

    protected $description = 'Print a recorded Stopwatch run (markdown with YAML frontmatter, or its debug stream as JSON)';

    public function handle(RunLogQuery $query): int
    {
        $idArg = $this->argument('id');
        $requested = is_string($idArg) ? $idArg : '';
        $id = $query->resolveId($requested);

        if ($id === null) {
            $this->components->error(DebugNotice::emptyMessage('No runs recorded yet.'));

            return self::FAILURE;
        }

        if (! $query->exists($id)) {
            $this->components->error("Run [{$id}] not found.");

            return self::FAILURE;
        }

        return $this->option('format') === 'json'
            ? $this->renderJson($query, $id)
            : $this->renderMarkdown($query, $id);
    }

    private function renderMarkdown(RunLogQuery $query, string $id): int
    {
        $contents = $query->markdown($id);

        if ($contents === null) {
            $this->components->error("Could not read run [{$id}].");

            return self::FAILURE;
        }

        $lines = preg_split('/\r\n|\n|\r/', $contents);

        foreach ($lines === false ? [] : $lines as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    private function renderJson(RunLogQuery $query, string $id): int
    {
        $records = $query->records($id);

        if ($records === null) {
            $this->components->error(RunLogQuery::missingStreamMessage($id));

            return self::FAILURE;
        }

        $this->line((string) json_encode($records, StopwatchCheckpoint::SAFE_JSON_FLAGS | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
