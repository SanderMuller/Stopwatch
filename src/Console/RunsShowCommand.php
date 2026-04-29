<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Console;

use Illuminate\Console\Command;
use SanderMuller\Stopwatch\RunLog\RunLogStore;

final class RunsShowCommand extends Command
{
    protected $signature = 'stopwatch:runs:show {id : The ULID of the run to inspect}';

    protected $description = 'Print a recorded Stopwatch run (markdown with YAML frontmatter)';

    public function handle(RunLogStore $store): int
    {
        $idArg = $this->argument('id');
        $id = is_string($idArg) ? $idArg : '';
        $path = $store->getRunPath($id);

        if ($path === null) {
            $this->components->error("Run [{$id}] not found.");

            return self::FAILURE;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            $this->components->error("Could not read run [{$id}].");

            return self::FAILURE;
        }

        $lines = preg_split('/\r\n|\n|\r/', $contents);

        foreach ($lines === false ? [] : $lines as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
