# AI assistant integration

The package ships two AI [skills](https://docs.claude.com/en/docs/claude-code/skills):

- `stopwatch-profile` — where the time goes: checkpoints, trackers, reading the card, driving the [run-log](09-run-log.md) commands, production tripwires.
- `stopwatch-debug` — why the code does the wrong thing: a hypothesis, `probe()` calls with values, [debug mode](11-debugging.md), reading `stopwatch:runs:show latest`, comparing runs with `stopwatch:runs:diff`, and removing the probes afterwards.

With [`laravel/boost`](https://github.com/laravel/boost) installed it is auto-discovered from `vendor/sandermuller/stopwatch/resources/boost/skills/`; run `php artisan boost:install`. Any Boost-compatible agent works: Claude Code, Cursor, Copilot.

## Letting it debug

With `STOPWATCH_DEBUG=true` (and `APP_DEBUG=true`, or a `local` / `testing` environment), *"the discount is wrong for repeat customers, find out why"* is enough. The assistant will:

1. Write down what it expects the value to be, and where.
2. Add `stopwatch()->probe('label', ['total' => $total])` on both sides of the suspect branch.
3. Run the failing test or command itself.
4. Read `stopwatch:runs:show latest`: the rows in execution order, the values, and the `file:line` of each probe.
5. Fix the cause, check it with `stopwatch:runs:diff <before> latest`, and remove every `stopwatch()->probe(` call.

To make a forgotten probe fail CI, include the shipped PHPStan rule:

```neon
includes:
    - vendor/sandermuller/stopwatch/resources/phpstan/no-probes.neon
```

## MCP

With `laravel/mcp` installed, the same reads are MCP tools: `stopwatch-list-runs`, `stopwatch-show-run`, `stopwatch-diff-runs` and `stopwatch-clear-runs` (the last one needs `confirm: true`). They show up in Laravel Boost's MCP server automatically, or run them as their own server:

```json
{ "mcpServers": { "stopwatch": { "command": "php", "args": ["artisan", "mcp:start", "stopwatch"] } } }
```

## Letting it profile

With the run log on and the skill synced, *"the /admin/users page feels slow, can you figure out why?"* is enough. The assistant will:

1. Check `STOPWATCH_LOG_RUNS=true`, and turn it on if not.
2. Ask you to reproduce the slow request.
3. Run `stopwatch:runs:list --slow` and pick the worst offenders.
4. Run `stopwatch:runs:show <id>` on each, read the per-checkpoint table, and name the segment that owns the share.

The same loop you would run by hand, in [Debugging a slow request](09-run-log.md#debugging-a-slow-request).
