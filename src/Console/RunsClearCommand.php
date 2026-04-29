<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Console;

use Illuminate\Console\Command;
use SanderMuller\Stopwatch\RunLog\RunLogStore;

final class RunsClearCommand extends Command
{
    protected $signature = 'stopwatch:runs:clear
                            {--keep= : Keep only the most recent N runs}
                            {--days= : Delete runs older than N days}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Delete recorded Stopwatch runs (all, oldest, or beyond an age cap)';

    public function handle(RunLogStore $store): int
    {
        $keep = $this->intOption('keep');
        $days = $this->intOption('days');

        if (! $this->confirmDestructive($keep, $days)) {
            $this->components->warn('Aborted.');

            return self::SUCCESS;
        }

        $deleted = 0;

        if ($keep !== null) {
            $deleted += $store->pruneByCount($keep);
        }

        if ($days !== null) {
            $deleted += $store->pruneByAge($days);
        }

        if ($keep === null && $days === null) {
            $deleted += $store->clear();
        }

        $this->components->info("Deleted {$deleted} run(s).");

        return self::SUCCESS;
    }

    private function intOption(string $key): ?int
    {
        $value = $this->option($key);

        return is_numeric($value) ? (int) $value : null;
    }

    private function confirmDestructive(?int $keep, ?int $days): bool
    {
        if ($this->option('force') === true) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            return false;
        }

        $prompt = match (true) {
            $keep === null && $days === null => 'Delete ALL recorded Stopwatch runs?',
            $keep !== null && $days === null => "Keep only the most recent {$keep} run(s) and delete the rest?",
            $keep === null && $days !== null => "Delete runs older than {$days} day(s)?",
            default => "Keep only the most recent {$keep} run(s) AND delete runs older than {$days} day(s)?",
        };

        return $this->confirm($prompt, false);
    }
}
