<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\RunLog;

use Exception;
use RuntimeException;
use SanderMuller\Stopwatch\RunLog\ExceptionDetail;
use SanderMuller\Stopwatch\Tests\TestCase;

final class ExceptionDetailTest extends TestCase
{
    public function test_captures_class_file_and_line(): void
    {
        $exception = new RuntimeException('boom');
        $data = $this->builder()->build($exception);

        self::assertSame(RuntimeException::class, $data['class']);
        self::assertNotEmpty($data['file']);
        self::assertGreaterThan(0, $data['line']);
        self::assertArrayNotHasKey('message', $data);
    }

    public function test_message_only_included_when_enabled(): void
    {
        $exception = new RuntimeException('the user failed validation');

        $disabled = (new ExceptionDetail(messageEnabled: false))->build($exception);
        self::assertArrayNotHasKey('message', $disabled);

        $enabled = (new ExceptionDetail(messageEnabled: true))->build($exception);
        self::assertSame('the user failed validation', $enabled['message']);
    }

    public function test_message_capped_via_mb_substr_with_ellipsis(): void
    {
        $message = str_repeat('é', 600);  // 600 codepoints, multi-byte UTF-8
        $exception = new RuntimeException($message);
        $data = (new ExceptionDetail(messageEnabled: true, messageMaxChars: 500))->build($exception);

        self::assertNotNull($data['message'] ?? null);
        // 500 codepoints + the ellipsis suffix.
        self::assertSame(500 + 1, mb_strlen($data['message'] ?? '', 'UTF-8'));
        self::assertStringEndsWith('…', $data['message'] ?? '');
    }

    public function test_message_under_cap_has_no_ellipsis_suffix(): void
    {
        $exception = new RuntimeException('short message');
        $data = (new ExceptionDetail(messageEnabled: true, messageMaxChars: 500))->build($exception);

        self::assertSame('short message', $data['message'] ?? null);
    }

    public function test_substring_mask_redacts_message(): void
    {
        $exception = new RuntimeException('secret token=ABC123 leaked');
        $data = (new ExceptionDetail(
            messageEnabled: true,
            maskPatterns: ['token=ABC123'],
        ))->build($exception);

        self::assertSame('secret *** leaked', $data['message'] ?? null);
    }

    public function test_regex_mask_redacts_message(): void
    {
        $exception = new RuntimeException('card=4111111111111111 declined');
        $data = (new ExceptionDetail(
            messageEnabled: true,
            maskPatterns: ['/\b\d{16}\b/'],
        ))->build($exception);

        self::assertSame('card=*** declined', $data['message'] ?? null);
    }

    public function test_mask_applied_after_cap(): void
    {
        // Message: prefix (480 chars) + secret (10 chars) + suffix (110 chars).
        // Cap=500. Cut takes first 500 chars (prefix + first 20 of "secret-payload-1234567890"-style).
        // Mask runs on the capped text only — pattern matching the suffix has no effect.
        $message = str_repeat('a', 480) . 'SECRET' . str_repeat('z', 110);
        $exception = new RuntimeException($message);

        $data = (new ExceptionDetail(
            messageEnabled: true,
            messageMaxChars: 500,
            maskPatterns: ['SECRET'],
        ))->build($exception);

        $msg = $data['message'] ?? '';
        // Prefix preserved; SECRET masked; trailing 'z's outside the cap absent; ellipsis appended.
        self::assertStringContainsString(str_repeat('a', 480), $msg);
        self::assertStringContainsString('***', $msg);
        self::assertStringNotContainsString('SECRET', $msg);
        self::assertStringEndsWith('…', $msg);
    }

    public function test_mask_after_cap_does_not_match_truncated_tokens(): void
    {
        // Token straddles the cap boundary — only first half remains. Mask must NOT match
        // the partial. We assert the half token is preserved literally.
        $message = str_repeat('a', 498) . 'SECRET-rest';
        $exception = new RuntimeException($message);

        $data = (new ExceptionDetail(
            messageEnabled: true,
            messageMaxChars: 500,
            maskPatterns: ['SECRET-rest'],
        ))->build($exception);

        $msg = $data['message'] ?? '';
        // Capped at 500 chars + ellipsis. The partial 'SE' survives un-masked.
        self::assertStringContainsString('SE…', $msg);
    }

    public function test_invalid_regex_mask_silently_no_op(): void
    {
        $exception = new RuntimeException('untouched');
        $data = (new ExceptionDetail(
            messageEnabled: true,
            maskPatterns: ['/[invalid('],  // unbalanced — preg_replace returns null
        ))->build($exception);

        // Falls through gracefully; original message returned.
        self::assertSame('untouched', $data['message'] ?? null);
    }

    public function test_trace_frames_respects_cap(): void
    {
        $exception = $this->throwAtDepth(20);
        $data = (new ExceptionDetail(traceFrames: 5))->build($exception);

        self::assertCount(5, $data['frames']);
    }

    public function test_trace_frames_zero_omits_section(): void
    {
        $exception = new RuntimeException('boom');
        $data = (new ExceptionDetail(traceFrames: 0))->build($exception);

        self::assertSame([], $data['frames']);
    }

    public function test_trace_exclude_paths_filters_frames(): void
    {
        $exception = $this->throwAtDepth(10);

        // Exclude the test file itself (it shows up in every frame as the caller).
        $excludePattern = basename(__FILE__);
        $data = (new ExceptionDetail(
            traceFrames: 20,
            traceExcludePaths: [$excludePattern],
        ))->build($exception);

        foreach ($data['frames'] as $frame) {
            self::assertStringNotContainsString($excludePattern, $frame['file'] ?? '');
        }
    }

    public function test_args_never_persisted_in_frames(): void
    {
        $exception = $this->throwWithArgsInScope('secret-arg-value', ['nested' => 'data']);
        $data = (new ExceptionDetail(traceFrames: 20))->build($exception);

        foreach ($data['frames'] as $frame) {
            self::assertArrayNotHasKey('args', $frame);
        }

        // Defensive: stringify the whole shape and check the secret never leaks.
        $serialised = (string) json_encode($data);
        self::assertStringNotContainsString('secret-arg-value', $serialised);
    }

    public function test_one_level_of_previous_walked(): void
    {
        $root = new RuntimeException('original cause');
        $wrapped = new Exception('wrapped layer 1', 0, $root);
        $outer = new Exception('outer layer 2', 0, $wrapped);

        $data = (new ExceptionDetail(messageEnabled: true))->build($outer);

        // Outer is the captured exception.
        self::assertSame(Exception::class, $data['class']);
        self::assertSame('outer layer 2', $data['message'] ?? null);

        // Exactly ONE level of previous — the immediate wrap.
        self::assertSame(Exception::class, $data['previous']['class'] ?? null);
        self::assertSame('wrapped layer 1', $data['previous']['message'] ?? null);

        // The deeper RuntimeException root is NOT in the persisted shape.
        $serialised = (string) json_encode($data);
        self::assertStringNotContainsString('original cause', $serialised);
    }

    public function test_no_previous_section_when_chain_empty(): void
    {
        $exception = new RuntimeException('lone');
        $data = $this->builder()->build($exception);

        self::assertArrayNotHasKey('previous', $data);
    }

    public function test_previous_message_respects_message_enabled_flag(): void
    {
        $previous = new RuntimeException('previous message');
        $outer = new RuntimeException('outer', 0, $previous);

        $disabled = (new ExceptionDetail(messageEnabled: false))->build($outer);
        self::assertArrayHasKey('previous', $disabled);
        self::assertArrayNotHasKey('message', $disabled['previous']);

        $enabled = (new ExceptionDetail(messageEnabled: true))->build($outer);
        self::assertSame('previous message', $enabled['previous']['message'] ?? null);
    }

    private function builder(): ExceptionDetail
    {
        return new ExceptionDetail();
    }

    private function throwAtDepth(int $depth): RuntimeException
    {
        if ($depth <= 0) {
            return new RuntimeException('deep');
        }

        return $this->throwAtDepth($depth - 1);
    }

    private function throwWithArgsInScope(string $secret, array $bag): RuntimeException
    {
        // The `$secret` and `$bag` should appear in `$e->getTrace()[0]['args']` but must
        // never make it into the persisted shape. Constructed inside a function call so
        // the args are recorded in the backtrace.
        return $this->throwAt($secret, $bag);
    }

    private function throwAt(string $secret, array $bag): RuntimeException
    {
        return new RuntimeException('with args');
    }
}
