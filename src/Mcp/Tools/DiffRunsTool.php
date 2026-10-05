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

#[Name('stopwatch-diff-runs')]
#[Description('Compare two Stopwatch runs recorded with STOPWATCH_DEBUG=true, for example before and after a fix: per checkpoint the time change, query and HTTP counts, changed metadata values, and checkpoints present in one run only.')]
#[IsReadOnly]
final class DiffRunsTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'a' => $schema->string()->description('First run ULID (before), or "latest".')->required(),
            'b' => $schema->string()->description('Second run ULID (after), or "latest".')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        // Resolved here, not injected: Boost calls handle($request) directly.
        $runLogQuery = app(RunLogQuery::class);

        $a = $runLogQuery->resolveId($this->stringArgument($request, 'a'));
        $b = $runLogQuery->resolveId($this->stringArgument($request, 'b'));

        if ($a === null || $b === null) {
            return Response::error(DebugNotice::emptyMessage('No runs recorded yet.'));
        }

        $diff = $runLogQuery->diff($a, $b);

        return is_string($diff)
            ? Response::error($diff)
            : Response::text((string) json_encode($diff, StopwatchCheckpoint::SAFE_JSON_FLAGS | JSON_PRETTY_PRINT));
    }

    private function stringArgument(Request $request, string $key): string
    {
        $value = $request->get($key);

        return is_string($value) ? $value : '';
    }
}
