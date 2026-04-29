<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use SanderMuller\Stopwatch\Stopwatch;

/**
 * Persistent run-log context provider that captures the currently-running
 * artisan command name (set on `CommandStarting`) and exposes it as
 * `['command' => 'app:reindex']` when invoked at {@see Stopwatch::finish()} time.
 *
 * Maintains a stack so `Artisan::call()` from inside a command — which fires
 * a nested `CommandStarting` / `CommandFinished` pair — does not corrupt the
 * outer command's context. The provider always reports the innermost active
 * command, restoring the previous value when the inner command finishes.
 *
 * Wired via {@see Stopwatch::pushRunContextProvider()}, which is appended once
 * by the service provider — so it survives `start()`/`reset()` and is evaluated
 * after the command's `handle()` has been allowed to write its own checkpoints.
 */
final class ConsoleCommandContextProvider
{
    /** @var list<string> */
    private array $stack = [];

    public function __construct(Dispatcher $events)
    {
        $events->listen(CommandStarting::class, function (CommandStarting $event): void {
            $this->stack[] = $event->command;
        });

        $events->listen(CommandFinished::class, function (CommandFinished $event): void {
            // Pop the matching frame. In normal nesting this is the top of the stack,
            // but defensively only pop if it matches — a misordered finished event
            // should not corrupt the outer context.
            if ($this->stack !== [] && end($this->stack) === $event->command) {
                array_pop($this->stack);
            }
        });
    }

    /**
     * @return array<string, scalar|null>
     */
    public function __invoke(): array
    {
        if ($this->stack === []) {
            return [];
        }

        return ['command' => end($this->stack)];
    }
}
