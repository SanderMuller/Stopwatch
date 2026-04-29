<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * Captures `Illuminate\Support\Facades\Context::all()` (visible keys only — hidden
 * context is **never** read) into the persistable shape consumed by the run-log
 * recorder.
 *
 * The class is defensive on every axis:
 *  - Facade unbootable / not yet resolved → empty result + single warning log.
 *  - Non-scalar values when `allow=[]` → silently skipped (rich objects opt in via
 *    explicit allowlist) — never auto-leak a User Eloquent model into the body.
 *  - Per-value byte cap ({@see ContextValueRenderer}) prevents megabyte values
 *    from blowing up the run-log file.
 *  - Promoted `ctx_*` frontmatter lines are bounded both per-key (256 chars) and
 *    in total (2048 bytes) — see {@see ContextPromoter}.
 */
final readonly class ContextCapture
{
    private ContextValueRenderer $valueRenderer;

    /**
     * @param list<string> $allow empty = include only scalar visible keys; non-empty = allowlist
     * @param list<string> $deny applied after allow
     * @param list<string> $mask replace value with `***` while preserving the key
     * @param list<string> $frontmatterKeys promote scalar values to frontmatter as `ctx_<key>`
     */
    public function __construct(
        private array $allow = [],
        private array $deny = [],
        private array $mask = [],
        private array $frontmatterKeys = [],
        int $valueMaxBytes = 4096,
    ) {
        $this->valueRenderer = new ContextValueRenderer($valueMaxBytes);
    }

    /**
     * @return array{frontmatter_lines: list<string>, body: array<string, string>}
     */
    public function capture(): array
    {
        $raw = $this->readContext();

        if ($raw === null) {
            return ['frontmatter_lines' => [], 'body' => []];
        }

        $filtered = $this->applyDeny($this->applyAllow($raw));
        $filtered = $this->applyMask($filtered);

        return $this->split($filtered);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readContext(): ?array
    {
        try {
            $raw = Context::all();
        } catch (Throwable $throwable) {
            $this->logFailure($throwable);

            return null;
        }

        $typed = [];

        /** @var mixed $value */
        foreach ($raw as $key => $value) {
            if (is_string($key)) {
                $typed[$key] = $value;
            }
        }

        return $typed;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function applyAllow(array $raw): array
    {
        if ($this->allow !== []) {
            return array_intersect_key($raw, array_flip($this->allow));
        }

        // Default policy: capture only scalar visible keys. Non-scalars must be
        // explicitly allowlisted (see spec §3.2). Log each drop so users wondering
        // why an expected key never appeared in the run-log have a debug breadcrumb.
        $kept = [];

        foreach ($raw as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $kept[$key] = $value;

                continue;
            }

            ContextCaptureLogger::skip('context capture', $key, 'non-scalar Context value skipped — add the key to options.context.allow to include');
        }

        return $kept;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function applyDeny(array $values): array
    {
        if ($this->deny === []) {
            return $values;
        }

        return array_diff_key($values, array_flip($this->deny));
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function applyMask(array $values): array
    {
        foreach ($this->mask as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '***';
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $filtered
     * @return array{frontmatter_lines: list<string>, body: array<string, string>}
     */
    private function split(array $filtered): array
    {
        $contextPromoter = new ContextPromoter($this->frontmatterKeys);
        $body = [];

        foreach ($filtered as $key => $value) {
            $body[$key] = $this->valueRenderer->render($value);
            $contextPromoter->consider($key, $value);
        }

        return ['frontmatter_lines' => $contextPromoter->lines(), 'body' => $body];
    }

    private function logFailure(Throwable $e): void
    {
        try {
            if (function_exists('logger')) {
                logger()->warning(
                    'Stopwatch context capture failed: ' . $e->getMessage(),
                    ['exception' => $e],
                );
            }
        } catch (Throwable) {
            // logger unavailable — swallow.
        }
    }
}
