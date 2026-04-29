<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

use Carbon\CarbonImmutable;

/**
 * Filesystem-backed store for run-log markdown files.
 *
 * Files are named `<ULID>.md` so they sort chronologically by filename and
 * concurrent writers never target the same path. Reads of `listRuns()` only
 * ingest each file's frontmatter block (the first `---`-delimited section),
 * so listing 200 files is cheap.
 *
 * @phpstan-import-type ParsedFrontmatter from RunLogReader
 */
final readonly class RunLogStore
{
    public function __construct(
        private string $path,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function ensureReady(): void
    {
        if (! is_dir($this->path)) {
            @mkdir($this->path, 0755, true);
        }

        $gitignore = $this->path . '/.gitignore';

        if (! file_exists($gitignore)) {
            @file_put_contents($gitignore, "*.md\n");
        }
    }

    public function write(string $id, string $contents): bool
    {
        $this->ensureReady();

        $finalPath = $this->filePath($id);
        $tmpPath = $finalPath . '.tmp';

        // Write to a sibling tmp file then rename — POSIX rename is atomic, so a concurrent
        // listRuns() / show never observes a partially-written file.
        if (@file_put_contents($tmpPath, $contents) === false) {
            return false;
        }

        if (! @rename($tmpPath, $finalPath)) {
            @unlink($tmpPath);

            return false;
        }

        return true;
    }

    public function getRunPath(string $id): ?string
    {
        $file = $this->filePath($id);

        return is_file($file) ? $file : null;
    }

    /**
     * @return array{frontmatter: ParsedFrontmatter, body: string}|null
     */
    public function getRun(string $id): ?array
    {
        $file = $this->getRunPath($id);

        if ($file === null) {
            return null;
        }

        $contents = (string) @file_get_contents($file);

        return [
            'frontmatter' => Frontmatter::parse($contents),
            'body' => Frontmatter::strip($contents),
        ];
    }

    /**
     * @return list<array{id: string, frontmatter: ParsedFrontmatter}>
     */
    public function listRuns(int $max = 30, string $sortBy = 'duration_ms', bool $descending = true): array
    {
        return (new RunLogReader($this->path))->list($max, $sortBy, $descending);
    }

    public function clear(): int
    {
        return $this->deleteAll($this->files());
    }

    /**
     * Delete oldest files (by ULID-sort, which is chronological) until at most $maxFiles remain.
     */
    public function pruneByCount(int $maxFiles): int
    {
        if ($maxFiles < 0) {
            return 0;
        }

        $files = $this->files();

        if (count($files) <= $maxFiles) {
            return 0;
        }

        sort($files);

        return $this->deleteAll(array_slice($files, 0, count($files) - $maxFiles));
    }

    /**
     * Delete files whose ULID timestamp is older than $maxAgeDays days.
     *
     * ULID timestamps are derived from the filename (the first 10 base32 chars encode
     * the millisecond UTC timestamp). This is robust against `touch`, file copies, and
     * filesystem mtime drift in a way an `mtime`-based prune is not.
     *
     * Files whose ULID cannot be decoded fall back to mtime so a corrupt filename is
     * still subject to age cleanup rather than living forever.
     */
    public function pruneByAge(int $maxAgeDays): int
    {
        if ($maxAgeDays <= 0) {
            return 0;
        }

        $cutoffMs = CarbonImmutable::now()->subDays($maxAgeDays)->getTimestamp() * 1000;

        return $this->deleteAll(array_filter(
            $this->files(),
            static fn (string $file): bool => self::isOlderThan($file, $cutoffMs),
        ));
    }

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
    private function files(): array
    {
        if (! is_dir($this->path)) {
            return [];
        }

        $files = glob($this->path . '/*.md');

        return $files === false ? [] : $files;
    }

    /**
     * @param iterable<string> $files
     */
    private function deleteAll(iterable $files): int
    {
        $deleted = 0;

        foreach ($files as $file) {
            if (@unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function filePath(string $id): string
    {
        return $this->path . '/' . $id . '.md';
    }
}
