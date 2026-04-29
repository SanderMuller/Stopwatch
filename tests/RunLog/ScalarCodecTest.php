<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\RunLog;

use PHPUnit\Framework\Attributes\DataProvider;
use SanderMuller\Stopwatch\RunLog\ScalarCodec;
use SanderMuller\Stopwatch\Tests\TestCase;

final class ScalarCodecTest extends TestCase
{
    public function test_encode_handles_null_bool_int_float_unchanged(): void
    {
        self::assertSame('null', ScalarCodec::encode(null));
        self::assertSame('true', ScalarCodec::encode(true));
        self::assertSame('false', ScalarCodec::encode(false));
        self::assertSame('487', ScalarCodec::encode(487));
        self::assertSame('1.25', ScalarCodec::encode(1.25));
    }

    public function test_encode_strips_newlines_from_strings(): void
    {
        self::assertSame('a b c', ScalarCodec::encode("a\nb\rc"));
    }

    public function test_decode_existing_typed_field_round_trips(): void
    {
        // Built-in fields like duration_ms must still parse as int — Phase 2 must NOT
        // break the existing reader behaviour for fields written via encode().
        self::assertSame(487, ScalarCodec::decode('487'));
        self::assertSame(1.25, ScalarCodec::decode('1.25'));
        self::assertTrue(ScalarCodec::decode('true'));
        self::assertFalse(ScalarCodec::decode('false'));
        self::assertNull(ScalarCodec::decode('null'));
        self::assertNull(ScalarCodec::decode(''));
        self::assertSame('plain', ScalarCodec::decode('plain'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function ambiguousStringRoundTrip(): iterable
    {
        // [input, expected encoded form]
        yield 'literal-true' => ['true', "'true'"];
        yield 'literal-false' => ['false', "'false'"];
        yield 'literal-null' => ['null', "'null'"];
        yield 'literal-true-mixed-case' => ['True', "'True'"];
        yield 'integer-string' => ['01', "'01'"];
        yield 'leading-zero-decimal' => ['1.20', "'1.20'"];
        yield 'negative-integer' => ['-5', "'-5'"];
        yield 'negative-float' => ['-1.5', "'-1.5'"];
        yield 'leading-space' => [' leading', "' leading'"];
        yield 'trailing-space' => ['trailing ', "'trailing '"];
        yield 'empty-string' => ['', "''"];
        yield 'wrapped-in-quotes' => ["'wrapped'", "'''wrapped'''"];
    }

    #[DataProvider('ambiguousStringRoundTrip')]
    public function test_encode_string_safe_quotes_ambiguous_values(string $input, string $expectedEncoded): void
    {
        self::assertSame($expectedEncoded, ScalarCodec::encodeStringSafe($input));
        self::assertSame($input, ScalarCodec::decode($expectedEncoded));
    }

    /** @return iterable<string, array{string}> */
    public static function plainStringPassThrough(): iterable
    {
        // Strings that are NOT ambiguous — should NOT be quoted (back-compat with
        // existing frontmatter that was written before the codec extension).
        yield 'plain-word' => ['hello'];
        yield 'with-internal-spaces' => ['hello world'];
        yield 'url-path' => ['/admin/users'];
        yield 'http-method' => ['GET'];
        yield 'ulid' => ['01HZ8K9X4N5P2Q3R4S5T6U7V8W'];
        yield 'kebab' => ['acme-corp'];
        yield 'snake' => ['user_id'];
        yield 'mixed-case-noun' => ['OrderController'];
        yield 'with-colon' => ['cache:clear'];  // exercise the existing first-colon parser
        yield 'with-internal-apostrophe' => ["O'Brien"];  // not at edges → no decode ambiguity
        yield 'with-trailing-apostrophe' => ["it's a 'thing'"];  // ends with `'` but does not START with `'`
    }

    #[DataProvider('plainStringPassThrough')]
    public function test_encode_string_safe_leaves_plain_strings_unquoted(string $input): void
    {
        self::assertSame($input, ScalarCodec::encodeStringSafe($input));
        self::assertSame($input, ScalarCodec::decode($input));
    }

    public function test_encode_string_safe_defers_to_encode_for_non_strings(): void
    {
        self::assertSame('null', ScalarCodec::encodeStringSafe(null));
        self::assertSame('true', ScalarCodec::encodeStringSafe(true));
        self::assertSame('false', ScalarCodec::encodeStringSafe(false));
        self::assertSame('42', ScalarCodec::encodeStringSafe(42));
        self::assertSame('1.25', ScalarCodec::encodeStringSafe(1.25));
    }

    public function test_decode_strips_quotes_only_from_paired_outer_single_quotes(): void
    {
        // Single trailing quote — not a paired wrapper. Stays as literal.
        self::assertSame("foo'", ScalarCodec::decode("foo'"));
        // Single leading quote — same.
        self::assertSame("'foo", ScalarCodec::decode("'foo"));
        // Just one quote — too short for the `>= 2` guard.
        self::assertSame("'", ScalarCodec::decode("'"));
    }

    public function test_round_trip_preserves_apostrophes_inside_quoted_value(): void
    {
        $input = "it's complicated";
        $encoded = ScalarCodec::encodeStringSafe($input);
        // Whitespace is internal only, no leading/trailing — needsQuoting returns false.
        self::assertSame($input, $encoded);
        self::assertSame($input, ScalarCodec::decode($encoded));
    }
}
