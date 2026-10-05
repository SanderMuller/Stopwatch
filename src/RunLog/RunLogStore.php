<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

use Carbon\CarbonImmutable;

/**
 * Filesystem-backed store for run-log files.
 *
 * A run is `<ULID>.md` (the finished run) and/or `<ULID>.jsonl` (the debug
 * stream). Files sort chronologically by name, and concurrent writers never
 * target the same path. Prune and clear count both files of one id as one run.
 * `listRuns()` reads only the head of each `.md` and the first and last line of
 * each stream-only `.jsonl`, so listing 200 runs is cheap.
 *
 * @phpstan-import-type ParsedFrontmatter from RunLogReader
 */
final readonly class RunLogStore
{
    public const string DEBUG_BLOCKED_FILE = '.debug-blocked';

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

        RunsGitignore::ensure($this->path);
    }

    /**
     * Note that a process requested debug mode but a guard blocked it, so the read
     * commands (often run in another process and environment) can say why no run
     * was recorded.
     */
    public function writeDebugBlocked(string $reason): void
    {
        if (($this->debugBlocked()['reason'] ?? null) === $reason) {
            return;
        }

        $this->ensureReady();
        @file_put_contents($this->debugBlockedPath(), (string) json_encode([
            'reason' => $reason,
            'at' => CarbonImmutable::now()->format('Y-m-d\TH:i:sP'),
        ]));
    }

    /**
     * @return array{reason: string, at: string}|null
     */
    public function debugBlocked(): ?array
    {
        $decoded = json_decode((string) @file_get_contents($this->debugBlockedPath()), true);

        if (! is_array($decoded) || ! is_string($decoded['reason'] ?? null) || ! is_string($decoded['at'] ?? null)) {
            return null;
        }

        return ['reason' => $decoded['reason'], 'at' => $decoded['at']];
    }

    public function clearDebugBlocked(): void
    {
        @unlink($this->debugBlockedPath());
    }

    /**
     * Append one line to the run's JSONL stream. One process writes each file,
     * so the append needs no lock.
     */
    public function appendLine(string $id, string $line): bool
    {
        if (! $this->isSafeId($id)) {
            return false;
        }

        $this->ensureReady();

        return @file_put_contents($this->streamPath($id), $line . "\n", FILE_APPEND) !== false;
    }

    public function getStreamPath(string $id): ?string
    {
        if (! $this->isSafeId($id)) {
            return null;
        }

        $file = $this->streamPath($id);

        return is_file($file) ? $file : null;
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
        // Ids reach this from commands and MCP tools; refuse anything that could leave the runs dir.
        if (! $this->isSafeId($id)) {
            return null;
        }

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
     * Newest run id across `.md` and `.jsonl` (ULIDs sort chronologically), or null.
     */
    public function latestId(): ?string
    {
        return $this->runFiles()->latestId();
    }

    /**
     * @return list<array{id: string, frontmatter: ParsedFrontmatter, state: string}>
     */
    public function listRuns(int $max = 30, string $sortBy = 'duration_ms', bool $descending = true): array
    {
        return (new RunLogReader($this->path))->list($max, $sortBy, $descending);
    }

    /**
     * Returns the number of runs deleted, not files.
     */
    public function clear(): int
    {
        $runFiles = $this->runFiles();

        return $runFiles->delete($runFiles->all());
    }

    /**
     * Delete the oldest runs (by ULID-sort, which is chronological) until at most
     * $maxFiles runs remain. A run's `.md` and `.jsonl` count as one.
     */
    public function pruneByCount(int $maxFiles): int
    {
        if ($maxFiles < 0) {
            return 0;
        }

        $runFiles = $this->runFiles();
        $runs = $runFiles->all();

        if (count($runs) <= $maxFiles) {
            return 0;
        }

        ksort($runs, SORT_STRING);

        return $runFiles->delete(array_slice($runs, 0, count($runs) - $maxFiles, preserve_keys: true));
    }

    /**
     * Delete runs whose ULID timestamp is older than $maxAgeDays days (see
     * {@see RunFiles::olderThan()}).
     */
    public function pruneByAge(int $maxAgeDays): int
    {
        if ($maxAgeDays <= 0) {
            return 0;
        }

        $cutoffMs = CarbonImmutable::now()->subDays($maxAgeDays)->getTimestamp() * 1000;

        $runFiles = $this->runFiles();

        return $runFiles->delete($runFiles->olderThan($cutoffMs));
    }

    private function runFiles(): RunFiles
    {
        return new RunFiles($this->path);
    }

    private function filePath(string $id): string
    {
        return $this->path . '/' . $id . '.md';
    }

    private function debugBlockedPath(): string
    {
        return $this->path . '/' . self::DEBUG_BLOCKED_FILE;
    }

    private function streamPath(string $id): string
    {
        return $this->path . '/' . $id . '.jsonl';
    }

    private function isSafeId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9_-]+$/', $id) === 1;
    }
}
