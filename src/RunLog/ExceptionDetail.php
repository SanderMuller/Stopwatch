<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

use Throwable;

/**
 * Pure data builder — converts a captured {@see Throwable} into the persistable
 * shape the run-log markdown writer needs.
 *
 * Cap + mask delegate to {@see MessageProcessor}; per-frame copy + exclude
 * filtering delegate to {@see FrameNormaliser}; path normalisation delegates to
 * {@see PathRelativiser}. Exactly one level of `getPrevious()` is unwrapped —
 * wrapped exceptions are common in Laravel; deeper chains risk unbounded
 * recursion vs. diminishing value.
 *
 * @phpstan-type Frame array{file?: string, line?: int, class?: string, function?: string, type?: string}
 * @phpstan-type ExceptionData array{
 *     class: string,
 *     file: string,
 *     line: int,
 *     message?: string,
 *     frames: list<Frame>,
 *     previous?: array{class: string, file: string, line: int, message?: string},
 * }
 */
final readonly class ExceptionDetail
{
    private MessageProcessor $messageProcessor;

    private FrameNormaliser $frameNormaliser;

    /**
     * @param list<string> $maskPatterns leading `/` = preg pattern, otherwise substring
     * @param list<string> $traceExcludePaths substring matches against (relativised) frame.file
     */
    public function __construct(
        private bool $messageEnabled = false,
        int $messageMaxChars = 500,
        array $maskPatterns = [],
        private int $traceFrames = 10,
        array $traceExcludePaths = [],
    ) {
        $this->messageProcessor = new MessageProcessor($messageMaxChars, $maskPatterns);
        $this->frameNormaliser = new FrameNormaliser($traceExcludePaths);
    }

    /**
     * @return ExceptionData
     */
    public function build(Throwable $exception): array
    {
        $data = [
            'class' => $exception::class,
            'file' => PathRelativiser::relativise($exception->getFile()),
            'line' => $exception->getLine(),
            'frames' => $this->buildFrames($exception->getTrace()),
        ];

        if ($this->messageEnabled) {
            $data['message'] = $this->messageProcessor->process($exception->getMessage());
        }

        $previous = $exception->getPrevious();

        if ($previous instanceof Throwable) {
            $data['previous'] = $this->buildPrevious($previous);
        }

        return $data;
    }

    /**
     * @return array{class: string, file: string, line: int, message?: string}
     */
    private function buildPrevious(Throwable $previous): array
    {
        $data = [
            'class' => $previous::class,
            'file' => PathRelativiser::relativise($previous->getFile()),
            'line' => $previous->getLine(),
        ];

        if ($this->messageEnabled) {
            $data['message'] = $this->messageProcessor->process($previous->getMessage());
        }

        return $data;
    }

    /**
     * @param list<array<string, mixed>> $rawFrames
     * @return list<Frame>
     */
    private function buildFrames(array $rawFrames): array
    {
        if ($this->traceFrames === 0) {
            return [];
        }

        $frames = [];

        foreach ($rawFrames as $rawFrame) {
            if (count($frames) >= $this->traceFrames) {
                break;
            }

            $built = $this->frameNormaliser->normalise($rawFrame);

            if ($built !== null) {
                $frames[] = $built;
            }
        }

        return $frames;
    }
}
