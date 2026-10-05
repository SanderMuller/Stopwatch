<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use SanderMuller\Stopwatch\RunLog\RunLogStore;

#[Name('stopwatch-clear-runs')]
#[Description('Delete every recorded Stopwatch run (markdown and debug stream). Pass confirm: true; without it nothing is deleted.')]
#[IsDestructive]
final class ClearRunsTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'confirm' => $schema->boolean()->description('Must be true to delete.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        // Resolved here, not injected: Boost calls handle($request) directly.
        $runLogStore = app(RunLogStore::class);

        if ($request->get('confirm') !== true) {
            return Response::error('Nothing deleted. Pass confirm: true to delete every recorded run.');
        }

        return Response::text('Deleted ' . $runLogStore->clear() . ' run(s).');
    }
}
