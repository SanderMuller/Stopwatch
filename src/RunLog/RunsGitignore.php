<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

/**
 * Keeps the runs directory out of git. An existing file written by an older
 * version (only `*.md`) gets the missing lines appended.
 *
 * @internal
 */
final class RunsGitignore
{
    private const array LINES = ['*.md', '*.jsonl', RunLogStore::DEBUG_BLOCKED_FILE];

    public static function ensure(string $directory): void
    {
        $gitignore = $directory . '/.gitignore';

        if (! file_exists($gitignore)) {
            @file_put_contents($gitignore, implode("\n", self::LINES) . "\n");

            return;
        }

        $existing = (string) @file_get_contents($gitignore);
        $missing = array_diff(self::LINES, array_map(trim(...), explode("\n", $existing)));

        if ($missing === []) {
            return;
        }

        $separator = $existing === '' || str_ends_with($existing, "\n") ? '' : "\n";
        @file_put_contents($gitignore, $separator . implode("\n", $missing) . "\n", FILE_APPEND);
    }
}
