<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use SanderMuller\Stopwatch\Console\RunListFilters;
use SanderMuller\Stopwatch\RunLog\DebugNotice;
use SanderMuller\Stopwatch\RunLog\RunLogStore;
use SanderMuller\Stopwatch\StopwatchCheckpoint;

#[Name('stopwatch-list-runs')]
#[Description('List recorded Stopwatch runs, newest first. Each row has the run id, frontmatter (duration, URL or command or job, status) and state: finished, shutdown, restarted or unfinished (a crash or a run still active).')]
#[IsReadOnly]
final class ListRunsTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('Maximum number of runs to return (default 10).'),
            'slow' => $schema->boolean()->description('Only runs over the slow threshold.'),
            'threw' => $schema->boolean()->description('Only runs that threw an exception.'),
        ];
    }

    public function handle(Request $request): Response
    {
        // Resolved here, not injected: Boost calls handle($request) directly.
        $runLogStore = app(RunLogStore::class);

        $limit = $request->get('limit');
        $limit = is_int($limit) && $limit > 0 ? $limit : 10;

        $rows = RunListFilters::apply(
            rows: $runLogStore->listRuns(PHP_INT_MAX, 'recorded'),
            slow: $request->get('slow') === true,
            threw: $request->get('threw') === true,
            exceptionClass: null,
            ctxFilters: [],
        );

        if ($rows === []) {
            return Response::text(DebugNotice::emptyMessage('No runs recorded yet. Set STOPWATCH_DEBUG=true (or STOPWATCH_LOG_RUNS=true) and reproduce.'));
        }

        return Response::text((string) json_encode(array_slice($rows, 0, $limit), StopwatchCheckpoint::SAFE_JSON_FLAGS | JSON_PRETTY_PRINT));
    }
}
