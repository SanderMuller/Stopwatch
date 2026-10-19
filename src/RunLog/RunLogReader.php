<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

use Illuminate\Support\Arr;

/**
 * Read-side helper for {@see RunLogStore}. Parses frontmatter from the head of
 * each markdown file (bounded I/O) and returns sorted summaries. A run that only
 * has a JSONL debug stream gets a summary built from its first and last record.
 *
 * `state`: `finished` when the markdown exists, else the stream's end reason
 * (`finished`, `restarted`, `shutdown`), else `unfinished`.
 *
 * @phpstan-type ParsedFrontmatter array<string, scalar|null>
 * @phpstan-type RunSummary array{id: string, frontmatter: ParsedFrontmatter, state: string}
 */
final readonly class RunLogReader
{
    /**
     * Cap on bytes read from each file when parsing frontmatter for listings.
     * Bumped from 4096 → 8192 to accommodate up to ~16 promoted `ctx_*` keys
     * (each capped at 256 chars after encoding) without truncating the close-fence.
     * Cheap on first-block I/O.
     */
    private const int FRONTMATTER_READ_BYTES = 8192;

    public function __construct(
        private string $path,
    ) {}

    /**
     * @return list<RunSummary>
     */
    public function list(int $max, string $sortBy, bool $descending): array
    {
        $rows = $this->collectFrontmatter();

        usort($rows, static function (array $a, array $b) use ($sortBy, $descending): int {
            $av = $sortBy === 'recorded' ? $a['id'] : ($a['frontmatter'][$sortBy] ?? null);
            $bv = $sortBy === 'recorded' ? $b['id'] : ($b['frontmatter'][$sortBy] ?? null);

            return $descending ? -($av <=> $bv) : ($av <=> $bv);
        });

        return array_slice($rows, 0, $max);
    }

    /**
     * @return list<RunSummary>
     */
    private function collectFrontmatter(): array
    {
        $rows = [];

        foreach ((new RunFiles($this->path))->all() as $id => $files) {
            $row = $this->summary((string) $id, $files);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param non-empty-list<string> $files
     * @return RunSummary|null
     */
    private function summary(string $id, array $files): ?array
    {
        $markdown = $this->fileWithExtension($files, 'md');
        $head = $markdown === null ? null : $this->readHead($markdown);
        $frontmatter = $head === null ? [] : Frontmatter::parse($head);

        if ($frontmatter !== []) {
            return ['id' => $id, 'frontmatter' => $frontmatter, 'state' => 'finished'];
        }

        $stream = $this->fileWithExtension($files, 'jsonl');

        return $stream === null ? null : StreamFile::summary($id, $stream);
    }

    /**
     * @param non-empty-list<string> $files
     */
    private function fileWithExtension(array $files, string $extension): ?string
    {
        return Arr::first($files, static fn (string $file): bool => pathinfo($file, PATHINFO_EXTENSION) === $extension);
    }

    private function readHead(string $file): ?string
    {
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return null;
        }

        $head = (string) @fread($handle, self::FRONTMATTER_READ_BYTES);
        @fclose($handle);

        return $head;
    }
}
