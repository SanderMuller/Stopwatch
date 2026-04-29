<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

/**
 * Decides which Context keys get promoted to frontmatter as `ctx_<key>` lines,
 * applies the round-trip-safe encoder ({@see ScalarCodec::encodeStringSafe}), and
 * enforces both per-value (256 chars) and total (2048 bytes) caps so promoted
 * fields cannot push later frontmatter past {@see RunLogReader::FRONTMATTER_READ_BYTES}.
 *
 * Non-scalar values + over-cap values silently drop (debug-logged) and are still
 * rendered in the body — promotion failure is never a hard error.
 */
final class ContextPromoter
{
    /** Per-key cap on encoded length. Anything longer is dropped from frontmatter. */
    private const int PER_KEY_MAX_CHARS = 256;

    /** Cumulative cap on all promoted lines combined. */
    private const int TOTAL_MAX_BYTES = 2048;

    private int $remainingBudget = self::TOTAL_MAX_BYTES;

    /**
     * @var list<string>
     */
    private array $lines = [];

    /**
     * @param list<string> $frontmatterKeys
     */
    public function __construct(
        private readonly array $frontmatterKeys,
    ) {}

    public function consider(string $key, mixed $value): void
    {
        if (! in_array($key, $this->frontmatterKeys, true)) {
            return;
        }

        if (! is_scalar($value) && $value !== null) {
            ContextCaptureLogger::skip('context promoter', $key, 'non-scalar value not promoted to frontmatter (still rendered in body)');

            return;
        }

        $safeKey = $this->sanitiseKey($key);

        if ($safeKey === '') {
            ContextCaptureLogger::skip('context promoter', $key, 'key sanitised to empty after stripping `:`/newline characters — promotion skipped');

            return;
        }

        $encoded = ScalarCodec::encodeStringSafe($value);

        if (strlen($encoded) > self::PER_KEY_MAX_CHARS) {
            ContextCaptureLogger::skip('context promoter', $key, 'encoded value exceeds 256-char per-key cap — promotion skipped');

            return;
        }

        $line = 'ctx_' . $safeKey . ': ' . $encoded;
        $lineSize = strlen($line);

        if ($lineSize > $this->remainingBudget) {
            ContextCaptureLogger::skip('context promoter', $key, 'cumulative 2048-byte frontmatter budget exceeded — promotion skipped');

            return;
        }

        $this->remainingBudget -= $lineSize;
        $this->lines[] = $line;
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * Strip characters that would break the line-based frontmatter parser. Keys
     * containing `\n`, `\r`, or `:` would either split into multiple lines or be
     * misread as `key: value` pairs by the decoder.
     */
    private function sanitiseKey(string $key): string
    {
        return trim(str_replace(["\r", "\n", ':'], '', $key));
    }
}
