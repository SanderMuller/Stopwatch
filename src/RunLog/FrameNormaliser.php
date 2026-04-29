<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

/**
 * Copies the persistable subset of a single PHP backtrace frame, skipping frames
 * whose (relativised) file matches any `traceExcludePaths` entry.
 *
 * Crucially, the `args` key is NEVER copied — `Throwable::getTrace()` includes
 * call arguments that frequently embed user-supplied request data (validation
 * input, request bodies, secrets). Strip them defensively even though we never
 * read them.
 *
 * @phpstan-import-type Frame from ExceptionDetail
 */
final readonly class FrameNormaliser
{
    /**
     * @param list<string> $excludePaths substring matches against the relativised file
     */
    public function __construct(
        private array $excludePaths,
    ) {}

    /**
     * @param array<string, mixed> $frame
     * @return Frame|null `null` when the frame's file matches an exclude pattern
     */
    public function normalise(array $frame): ?array
    {
        $file = $this->resolveFile($frame);

        if ($file === false) {
            return null;
        }

        return $this->buildFrame($file, $frame);
    }

    /**
     * @param array<string, mixed> $frame
     * @return Frame
     */
    private function buildFrame(?string $file, array $frame): array
    {
        $candidate = [
            'file' => $file,
            'line' => $this->pickInt($frame, 'line'),
            'class' => $this->pickString($frame, 'class'),
            'function' => $this->pickString($frame, 'function'),
            'type' => $this->pickString($frame, 'type'),
        ];

        return array_filter($candidate, static fn (string|int|null $v): bool => $v !== null);
    }

    /**
     * @param array<string, mixed> $frame
     */
    private function pickInt(array $frame, string $key): ?int
    {
        return isset($frame[$key]) && is_int($frame[$key]) ? $frame[$key] : null;
    }

    /**
     * @param array<string, mixed> $frame
     */
    private function pickString(array $frame, string $key): ?string
    {
        return isset($frame[$key]) && is_string($frame[$key]) ? $frame[$key] : null;
    }

    /**
     * @param array<string, mixed> $frame
     * @return string|null|false `false` signals "frame excluded by pattern", `null` signals "no file key".
     */
    private function resolveFile(array $frame): string|null|false
    {
        if (! isset($frame['file']) || ! is_string($frame['file'])) {
            return null;
        }

        $relative = PathRelativiser::relativise($frame['file']);

        return $this->isExcluded($relative) ? false : $relative;
    }

    private function isExcluded(string $relativeFile): bool
    {
        foreach ($this->excludePaths as $excludePath) {
            if ($excludePath !== '' && str_contains($relativeFile, $excludePath)) {
                return true;
            }
        }

        return false;
    }
}
