# AI assistant integration

The package ships an AI [skill](https://docs.claude.com/en/docs/claude-code/skills) that teaches an assistant when to reach for `stopwatch()`: where to put checkpoints, which trackers to turn on, how to read the card, how to drive the [run-log](08-run-log.md) commands, and how to wire production tripwires.

With [`laravel/boost`](https://github.com/laravel/boost) installed it is auto-discovered from `vendor/sandermuller/stopwatch/resources/boost/skills/` — run `php artisan boost:install`. Any Boost-compatible agent works: Claude Code, Cursor, Copilot.

## Letting it drive

With the run log on and the skill synced, *"the /admin/users page feels slow, can you figure out why?"* is enough. The assistant will:

1. Check `STOPWATCH_LOG_RUNS=true`, and turn it on if not.
2. Ask you to reproduce the slow request.
3. Run `stopwatch:runs:list --slow` and pick the worst offenders.
4. Run `stopwatch:runs:show <id>` on each, read the per-checkpoint table, and name the segment that owns the share.

The same loop you would run by hand, in [Debugging a slow request](08-run-log.md#debugging-a-slow-request).
