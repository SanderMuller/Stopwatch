<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

/**
 * Read operations on the run log, shared by the `stopwatch:runs:*` commands and
 * the MCP tools so both give the same answers.
 *
 * @phpstan-import-type Diff from RunDiff
 *
 * @internal
 */
final readonly class RunLogQuery
{
    public const string LATEST = 'latest';

    public function __construct(
        private RunLogStore $store,
    ) {}

    /**
     * Resolves `latest` to the newest run id (null when there are no runs); returns
     * any other value unchanged.
     */
    public function resolveId(string $idOrLatest): ?string
    {
        return $idOrLatest === self::LATEST ? $this->store->latestId() : $idOrLatest;
    }

    public function exists(string $id): bool
    {
        return $this->store->getRunPath($id) !== null || $this->store->getStreamPath($id) !== null;
    }

    /**
     * The run as markdown: the recorded markdown file when the run finished, else
     * the JSONL stream rendered as a table. Null when the run has neither file, or
     * when its markdown file cannot be read.
     */
    public function markdown(string $id): ?string
    {
        $path = $this->store->getRunPath($id);

        if ($path !== null) {
            $contents = @file_get_contents($path);

            return $contents === false ? null : $contents;
        }

        $streamPath = $this->store->getStreamPath($id);

        if ($streamPath === null) {
            return null;
        }

        return JsonlRunRenderer::render(StreamFile::records($streamPath), StreamFile::summary($id, $streamPath)['state']);
    }

    /**
     * The run's JSONL records, or null when the run has no stream.
     *
     * @return list<array<string, mixed>>|null
     */
    public function records(string $id): ?array
    {
        $path = $this->store->getStreamPath($id);

        return $path === null ? null : StreamFile::records($path);
    }

    /**
     * @return Diff|string the diff, or an error message when a run or its stream is missing
     */
    public function diff(string $a, string $b): array|string
    {
        $missing = $this->missing([$a, $b]);

        if ($missing !== null) {
            return $missing;
        }

        $recordsA = $this->records($a);
        $recordsB = $this->records($b);

        if ($recordsA === null || $recordsB === null) {
            return self::missingStreamMessage($recordsA === null ? $a : $b);
        }

        return RunDiff::compare($a, $b, $recordsA, $recordsB);
    }

    public static function missingStreamMessage(string $id): string
    {
        return "Run [{$id}] has no JSONL stream. Record it with STOPWATCH_DEBUG=true.";
    }

    /**
     * @param list<string> $ids
     */
    private function missing(array $ids): ?string
    {
        foreach ($ids as $id) {
            if (! $this->exists($id)) {
                return "Run [{$id}] not found.";
            }
        }

        return null;
    }
}
