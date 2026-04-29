<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\RunLog;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use SanderMuller\Stopwatch\RunLog\ContextCapture;
use SanderMuller\Stopwatch\Tests\TestCase;
use stdClass;

final class ContextCaptureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Context::flush();
    }

    public function test_empty_allow_captures_only_scalar_visible_keys(): void
    {
        Context::add('trace_id', '01HZULID');
        Context::add('user_id', 42);
        Context::add('rich_object', ['nested' => 'data']);  // non-scalar — must be skipped

        $result = (new ContextCapture())->capture();

        self::assertArrayHasKey('trace_id', $result['body']);
        self::assertArrayHasKey('user_id', $result['body']);
        self::assertArrayNotHasKey('rich_object', $result['body']);
    }

    public function test_explicit_allow_captures_named_keys_regardless_of_type(): void
    {
        Context::add('trace_id', '01HZULID');
        Context::add('rich_object', ['nested' => 'data']);
        Context::add('untracked', 'invisible');

        $result = (new ContextCapture(allow: ['trace_id', 'rich_object']))->capture();

        self::assertArrayHasKey('trace_id', $result['body']);
        self::assertArrayHasKey('rich_object', $result['body']);
        self::assertStringContainsString('"nested":"data"', $result['body']['rich_object']);
        self::assertArrayNotHasKey('untracked', $result['body']);
    }

    public function test_deny_excludes_keys_after_allow(): void
    {
        Context::add('keep_me', 'a');
        Context::add('drop_me', 'b');

        $result = (new ContextCapture(deny: ['drop_me']))->capture();

        self::assertArrayHasKey('keep_me', $result['body']);
        self::assertArrayNotHasKey('drop_me', $result['body']);
    }

    public function test_mask_replaces_value_but_preserves_key(): void
    {
        Context::add('trace_id', 'public');
        Context::add('credit_card', '4111111111111111');

        $result = (new ContextCapture(mask: ['credit_card']))->capture();

        self::assertSame('public', $result['body']['trace_id']);
        self::assertSame('***', $result['body']['credit_card']);
    }

    public function test_hidden_context_never_appears(): void
    {
        Context::add('public_key', 'visible');
        Context::addHidden('secret_key', 'must-not-appear');

        $result = (new ContextCapture())->capture();

        self::assertArrayHasKey('public_key', $result['body']);
        self::assertArrayNotHasKey('secret_key', $result['body']);
    }

    public function test_frontmatter_keys_promote_scalars(): void
    {
        Context::add('trace_id', '01HZULID');
        Context::add('tenant_id', 'acme');

        $result = (new ContextCapture(frontmatterKeys: ['trace_id', 'tenant_id']))->capture();

        self::assertContains('ctx_trace_id: 01HZULID', $result['frontmatter_lines']);
        self::assertContains('ctx_tenant_id: acme', $result['frontmatter_lines']);
        // Body still contains the keys (un-prefixed).
        self::assertArrayHasKey('trace_id', $result['body']);
    }

    public function test_frontmatter_keys_silently_skip_non_scalars(): void
    {
        Context::add('rich', ['nested' => 'data']);

        $result = (new ContextCapture(
            allow: ['rich'],
            frontmatterKeys: ['rich'],
        ))->capture();

        self::assertSame([], $result['frontmatter_lines']);
        // But body still includes the JSON-encoded value.
        self::assertArrayHasKey('rich', $result['body']);
    }

    public function test_frontmatter_keys_skip_values_over_256_chars(): void
    {
        $longValue = str_repeat('x', 300);
        Context::add('long_key', $longValue);

        $result = (new ContextCapture(frontmatterKeys: ['long_key']))->capture();

        self::assertSame([], $result['frontmatter_lines']);
        self::assertArrayHasKey('long_key', $result['body']);
    }

    public function test_total_promoted_byte_budget_caps_further_promotions(): void
    {
        // ~200 chars per value × ~12 keys = ~2400 bytes total, exceeds the 2048-byte budget.
        $value = str_repeat('x', 200);
        $keys = [];
        for ($i = 0; $i < 12; $i++) {
            $key = 'pad_' . $i;
            $keys[] = $key;
            Context::add($key, $value);
        }

        $result = (new ContextCapture(frontmatterKeys: $keys))->capture();

        // Some promotions land, others get dropped; cumulative byte cost ≤ 2048.
        $total = array_sum(array_map(strlen(...), $result['frontmatter_lines']));
        self::assertLessThanOrEqual(2048, $total);
        self::assertLessThan(12, count($result['frontmatter_lines']));
    }

    public function test_string_value_round_trips_safely_via_encode_string_safe(): void
    {
        // Phase 2 regression: a user_id like "01" must round-trip as the string "01",
        // not the int 1 that the literal-coercion decoder would produce on a bare value.
        Context::add('user_code', '01');

        $result = (new ContextCapture(frontmatterKeys: ['user_code']))->capture();

        // Encoded line uses single quotes so the parser preserves the string.
        self::assertContains("ctx_user_code: '01'", $result['frontmatter_lines']);
    }

    public function test_body_byte_cap_truncates_with_marker(): void
    {
        $hugeValue = str_repeat('x', 10_000);
        Context::add('huge', $hugeValue);

        $result = (new ContextCapture(allow: ['huge'], valueMaxBytes: 1024))->capture();

        self::assertStringContainsString('… (truncated, original 10000 bytes)', $result['body']['huge']);
        self::assertLessThan(1200, strlen($result['body']['huge']));  // cap + suffix
    }

    public function test_unencodable_value_yields_placeholder(): void
    {
        $resource = fopen('php://memory', 'r');
        Context::add('resource_value', $resource);

        $result = (new ContextCapture(allow: ['resource_value']))->capture();

        self::assertStringStartsWith('<unencodable: resource', $result['body']['resource_value']);

        if (is_resource($resource)) {
            fclose($resource);
        }
    }

    public function test_facade_failure_yields_empty_result(): void
    {
        // Swap the underlying repository with a stub that throws — exercise the catch path.
        Context::swap(new class {
            public function all(): array
            {
                throw new \RuntimeException('facade boom');
            }

            public function __call(string $method, array $args): mixed
            {
                return null;
            }
        });

        $result = (new ContextCapture())->capture();

        self::assertSame(['frontmatter_lines' => [], 'body' => []], $result);

        Context::clearResolvedInstance(Context::getFacadeAccessor());
    }

    public function test_post_filter_empty_yields_empty_body(): void
    {
        // Nothing added — body must be empty so the recorder skips emitting `## Context`.
        $result = (new ContextCapture())->capture();

        self::assertSame([], $result['body']);
        self::assertSame([], $result['frontmatter_lines']);
    }

    public function test_mask_with_nonexistent_key_is_a_noop(): void
    {
        Context::add('present', 'visible');

        $result = (new ContextCapture(mask: ['absent']))->capture();

        // 'absent' isn't in Context — no body entry, no error.
        self::assertArrayHasKey('present', $result['body']);
        self::assertArrayNotHasKey('absent', $result['body']);
        self::assertSame('visible', $result['body']['present']);
    }

    public function test_frontmatter_key_containing_newline_is_sanitised_or_skipped(): void
    {
        // Defensive: a malformed config key with `\n` would otherwise break the
        // line-based frontmatter parser (the value half would be read as a new key).
        Context::add('clean', 'ok');
        Context::add("with\nnewline", 'should-not-corrupt-frontmatter');

        $result = (new ContextCapture(
            allow: ['clean', "with\nnewline"],
            frontmatterKeys: ['clean', "with\nnewline"],
        ))->capture();

        // No frontmatter line may contain a literal newline AFTER the prefix.
        foreach ($result['frontmatter_lines'] as $line) {
            self::assertStringNotContainsString("\n", $line);
            self::assertStringNotContainsString("\r", $line);
        }
    }

    public function test_skipped_keys_emit_debug_log_breadcrumb(): void
    {
        // Each silent drop now emits a debug log entry so users can answer the question
        // "why didn't my expected key appear in the run-log?"
        Log::shouldReceive('debug')
            ->atLeast()->once()
            ->with(\Mockery::pattern('/^Stopwatch context capture: non-scalar/'), \Mockery::on(static fn ($ctx): bool => isset($ctx['key']) && $ctx['key'] === 'rich'));

        Context::add('rich', ['nested' => 'data']);  // non-scalar with allow=[]

        (new ContextCapture())->capture();
    }

    public function test_object_value_json_encoded_in_body(): void
    {
        $object = new stdClass();
        $object->id = 42;
        $object->name = 'demo';

        Context::add('obj', $object);

        $result = (new ContextCapture(allow: ['obj']))->capture();

        self::assertSame('{"id":42,"name":"demo"}', $result['body']['obj']);
    }
}
