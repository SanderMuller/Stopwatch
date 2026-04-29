<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

/**
 * Read-side helper for {@see RunLogStore}. Parses frontmatter from the head of
 * each file (bounded I/O) and returns sorted summaries.
 *
 * @phpstan-type ParsedFrontmatter array<string, scalar|null>
 * @phpstan-type RunSummary array{id: string, frontmatter: ParsedFrontmatter}
 */
final readonly class RunLogReader
{
    /** Cap on bytes read from each file when parsing frontmatter for listings. */
    private const int FRONTMATTER_READ_BYTES = 4096;

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

        foreach ($this->files() as $file) {
            $head = $this->readHead($file);
            $frontmatter = $head === null ? [] : Frontmatter::parse($head);

            if ($frontmatter !== []) {
                $rows[] = [
                    'id' => pathinfo($file, PATHINFO_FILENAME),
                    'frontmatter' => $frontmatter,
                ];
            }
        }

        return $rows;
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
