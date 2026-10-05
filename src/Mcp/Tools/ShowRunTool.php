<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use SanderMuller\Stopwatch\RunLog\DebugNotice;
use SanderMuller\Stopwatch\RunLog\RunLogQuery;
use SanderMuller\Stopwatch\StopwatchCheckpoint;

#[Name('stopwatch-show-run')]
#[Description('Show one recorded Stopwatch run: the per-checkpoint table with timing, queries, HTTP calls, location and metadata. Format "markdown" (default) or "json" (the raw debug-stream records; needs STOPWATCH_DEBUG=true).')]
#[IsReadOnly]
final class ShowRunTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Run ULID, or "latest" (default) for the newest run.'),
            'format' => $schema->string()->enum(['markdown', 'json'])->description('Output format (default markdown).'),
        ];
    }

    public function handle(Request $request): Response
    {
        // Resolved here, not injected: Boost calls handle($request) directly.
        $runLogQuery = app(RunLogQuery::class);

        $requested = $request->get('id');
        $id = $runLogQuery->resolveId(is_string($requested) && $requested !== '' ? $requested : RunLogQuery::LATEST);

        if ($id === null) {
            return Response::error(DebugNotice::emptyMessage('No runs recorded yet.'));
        }

        if (! $runLogQuery->exists($id)) {
            return Response::error("Run [{$id}] not found.");
        }

        return $request->get('format') === 'json' ? $this->json($runLogQuery, $id) : $this->markdown($runLogQuery, $id);
    }

    private function json(RunLogQuery $query, string $id): Response
    {
        $records = $query->records($id);

        return $records === null
            ? Response::error(RunLogQuery::missingStreamMessage($id))
            : Response::text((string) json_encode($records, StopwatchCheckpoint::SAFE_JSON_FLAGS | JSON_PRETTY_PRINT));
    }

    private function markdown(RunLogQuery $query, string $id): Response
    {
        $markdown = $query->markdown($id);

        return $markdown === null ? Response::error("Could not read run [{$id}].") : Response::text($markdown);
    }
}
