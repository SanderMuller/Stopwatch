<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

/**
 * Converts a single Context value into a single-line string for the body table,
 * applying the per-value byte cap.
 *
 * - Scalars: stringified (`null` → `null`, `true`/`false` literal, ints/floats cast).
 * - Arrays/objects: `json_encode` with `JSON_UNESCAPED_SLASHES`. When `false`
 *   (resource, circular ref, malformed UTF-8), substitutes `<unencodable: <gettype>>`.
 *
 * Truncation appends `… (truncated, original N bytes)` so a reader can see the
 * value was capped without having to cross-check the cap config.
 */
final readonly class ContextValueRenderer
{
    public function __construct(
        private int $valueMaxBytes = 4096,
    ) {}

    public function render(mixed $value): string
    {
        $encoded = $this->encode($value);

        return $this->cap($encoded);
    }

    private function encode(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => $value,
            default => $this->encodeNonScalar($value),
        };
    }

    private function encodeNonScalar(mixed $value): string
    {
        $json = @json_encode($value, JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '<unencodable: ' . gettype($value) . '>';
    }

    private function cap(string $value): string
    {
        $bytes = strlen($value);

        if ($bytes <= $this->valueMaxBytes) {
            return $value;
        }

        // mb_strcut is byte-precise but codepoint-safe — never splits a multi-byte char.
        return mb_strcut($value, 0, $this->valueMaxBytes, 'UTF-8') . ' … (truncated, original ' . $bytes . ' bytes)';
    }
}
