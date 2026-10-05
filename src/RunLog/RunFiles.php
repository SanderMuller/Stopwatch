<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

/**
 * The files of the runs directory, grouped by run id: `<id>.md` and/or `<id>.jsonl`.
 *
 * @internal
 */
final readonly class RunFiles
{
    public function __construct(
        private string $path,
    ) {}

    /**
     * Two globs instead of GLOB_BRACE, which musl libc lacks.
     *
     * @return array<string, non-empty-list<string>>
     */
    public function all(): array
    {
        if (! is_dir($this->path)) {
            return [];
        }

        $runs = [];

        foreach ([...$this->glob('md'), ...$this->glob('jsonl')] as $file) {
            $runs[pathinfo($file, PATHINFO_FILENAME)][] = $file;
        }

        return $runs;
    }

    /**
     * Newest run id; ULIDs sort chronologically.
     */
    public function latestId(): ?string
    {
        $ids = array_map(strval(...), array_keys($this->all()));
        rsort($ids, SORT_STRING);

        return $ids[0] ?? null;
    }

    /**
     * @return array<string, non-empty-list<string>>
     */
    public function olderThan(int $cutoffMs): array
    {
        return array_filter(
            $this->all(),
            static fn (array $files): bool => self::isOlderThan($files[0], $cutoffMs),
        );
    }

    /**
     * Delete each run's files. Returns the number of runs with at least one file deleted.
     *
     * @param array<string, non-empty-list<string>> $runs
     */
    public function delete(array $runs): int
    {
        $deleted = 0;

        foreach ($runs as $run) {
            $removed = array_filter($run, static fn (string $file): bool => @unlink($file));

            if ($removed !== []) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * ULID timestamps are read from the file name, which is robust against `touch`,
     * copies and mtime drift. A file whose ULID cannot be decoded falls back to mtime.
     */
    private static function isOlderThan(string $file, int $cutoffMs): bool
    {
        $timestampMs = UlidTimestamp::decodeMs(pathinfo($file, PATHINFO_FILENAME));

        if ($timestampMs !== null) {
            return $timestampMs < $cutoffMs;
        }

        $mtime = @filemtime($file);

        return $mtime !== false && $mtime < (int) ($cutoffMs / 1000);
    }

    /**
     * @return list<string>
     */
    private function glob(string $extension): array
    {
        $files = glob($this->path . '/*.' . $extension);

        return $files === false ? [] : $files;
    }
}
