<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

/**
 * Caps and masks exception messages.
 *
 * Cap is `mb_substr` against the requested codepoint count so multi-byte UTF-8
 * is never split mid-codepoint. An ellipsis `…` (single codepoint) is appended
 * when the original exceeded the cap. Mask patterns run AFTER the cap so masked
 * tokens cannot push the result back over the limit.
 *
 * Pattern syntax: a pattern whose first character is `/` is treated as a preg
 * pattern (with delimiter); any other value is treated as a case-sensitive
 * substring. Each match is replaced with `***`.
 */
final readonly class MessageProcessor
{
    /**
     * @param list<string> $maskPatterns
     */
    public function __construct(
        private int $maxChars,
        private array $maskPatterns,
    ) {}

    public function process(string $message): string
    {
        $original = mb_strlen($message, 'UTF-8');
        $capped = mb_substr($message, 0, $this->maxChars, 'UTF-8');

        if ($original > $this->maxChars) {
            $capped .= '…';
        }

        foreach ($this->maskPatterns as $maskPattern) {
            $capped = $this->applyMask($capped, $maskPattern);
        }

        return $capped;
    }

    private function applyMask(string $message, string $pattern): string
    {
        if ($pattern === '') {
            return $message;
        }

        if (str_starts_with($pattern, '/')) {
            $result = @preg_replace($pattern, '***', $message);

            return is_string($result) ? $result : $message;
        }

        return str_replace($pattern, '***', $message);
    }
}
