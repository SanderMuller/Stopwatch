# Run Log: Exception detail + Laravel Context

## Overview

The `0.7.0` run log captures *what happened* (timing, queries, HTTP, memory) and *whether the request crashed* (`threw: true`), but it does not capture *what crashed* or *who/what tenant the request belonged to*. This spec adds two collectors:

1. **Exception detail** — when a request throws (and the middleware catches it on the way out), persist the exception class / file / line / capped message and a top-N stack trace. `Stopwatch::dd()` is **not** a capture point in v1 — see §8 for why and the manual workaround.
2. **Laravel Context** — capture `Illuminate\Support\Facades\Context::all()` (visible keys only) into the run-log file so `trace_id` / `tenant_id` / `user_id` show up next to the profile, letting the run-log pivot to the user's existing structured-log chain.

Both collectors follow the `barryvdh/laravel-debugbar` config pattern: a top-level `collect_<thing>` toggle plus a nested `options.<thing>` sub-array for per-collector tuning. Every option is overridable via `.env` so consumers can tweak behavior without publishing the config file.

This is a strictly additive change. No existing fields move or change shape; recorders that don't opt in pay zero cost.

---

## 1. Prior art (Laravel Debugbar config style)

Read at `barryvdh/laravel-debugbar@main`:

- Top-level `collectors` map: `'auth' => env('DEBUGBAR_COLLECTORS_AUTH', false)`. One env var per collector, boolean toggle.
- Nested `options.<collector>` sub-arrays for fine-grained tuning, e.g. `options.auth.show_name`, `options.db.backtrace`, `options.db.soft_limit`, `options.db.exclude_paths`.
- PII redaction via `masked` arrays — empty by default, user lists keys: `options.session.masked`, `options.symfony_request.masked`, `options.http_client.masked`, `options.config.masked`.
- Limits: `(int) env('DEBUGBAR_OPTIONS_DB_SOFT_LIMIT', 100)` — explicit cast + default.
- `exclude_paths` / `excluded` arrays for backtrace + event filtering — opt-in addition to package defaults.
- Hierarchical env naming: `DEBUGBAR_<SECTION>_<KEY>` mirrors the config key path. Discoverable via grep.

What we copy: the two-tier shape (`collect_<x>` toggle + `options.<x>` tuning), `masked` array for redaction, hierarchical env naming, explicit casts on env reads.

What we change: the `collect_<x>` toggle is the env name (e.g. `STOPWATCH_LOG_COLLECT_EXCEPTIONS`) — no separate `collectors.exceptions` sub-key, because we only have two collectors here and a flat name is shorter to type. Mirror Debugbar's nesting on the `options` side only.

---

## 2. Exception detail

### 2.1 What's captured

When a stopwatch run finishes via the middleware exception path (or any caller that sets `withTransientContext(Stopwatch::TRANSIENT_EXCEPTION, $e)` — see §2.4), the recorder captures:

| Field | Source | Where it lands |
|-------|--------|----------------|
| `exception_class` | `$e::class` | Frontmatter (scalar) |
| `exception_file` | `$e->getFile()` (project-relative when possible) | Frontmatter (scalar) |
| `exception_line` | `$e->getLine()` | Frontmatter (scalar) |
| `exception_message` | `$e->getMessage()`, capped + optionally masked | Body `## Exception` section |
| Top-N stack frames | `$e->getTrace()`, file + line + class + method only — never args | Body `## Exception` section |

**Frontmatter exposure** of class/file/line lets `stopwatch:runs:list --threw` render the exception class in the table without parsing the body. Message + trace go in the body because they are unbounded length and may contain PII.

### 2.2 Privacy

- **Message is opt-in.** `STOPWATCH_LOG_EXCEPTIONS_MESSAGE=false` by default. Validation messages frequently quote user input ("The email field must be a valid email address: 'user@…'"); persisting these to disk is a PII risk the user must opt into.
- **Mask patterns.** `options.exceptions.mask_message_matching` — list of patterns. **Pattern syntax**: a value whose first character is `/` is treated as a `preg_replace` regex (full delimiter, e.g. `'/\b\d{16}\b/'`); any other value is treated as a case-sensitive substring. Each match is replaced with `***`. Applied AFTER the cap so masked tokens don't push past the limit.
- **Message cap.** `STOPWATCH_LOG_EXCEPTIONS_MESSAGE_MAX_CHARS=500`. Cut via `mb_substr($message, 0, $max, 'UTF-8')` so multi-byte characters are not split mid-codepoint. When a cut occurs, append `…` (single U+2026, fits in any console). The cap counts characters, not bytes.
- **Trace** captures `file`, `line`, `class`, `function`, `type` from each frame. **Never `args`** — `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)` is the analogue. We work from `$e->getTrace()` which already includes args; we strip them when persisting.
- **Trace path filtering.** `options.exceptions.trace_exclude_paths` — substring matches against `frame.file`. Empty by default. Useful for hiding vendor noise (`vendor/laravel/framework/src/Illuminate/Routing`) so the trace shows the user's own stack.
- **Path relativisation for `exception_file` and trace `frame.file`.** Three cases:
  1. `base_path()` resolves AND absolute path starts with that prefix → strip the prefix and any leading `/` (e.g. `app/Http/Controllers/OrderController.php`). Portable across machines.
  2. Path contains a `/vendor/` segment (Composer convention) → emit `vendor/` + the substring after `/vendor/` (e.g. `/usr/local/share/php/vendor/laravel/framework/src/...` → `vendor/laravel/framework/src/...`). Useful when Composer's vendor lives outside `base_path()` (rare but real, e.g. monorepo layouts, deployed PHARs, system-wide installs).
  3. Neither matches → emit `<external>/` + the file's basename only (`<external>/foo.php`). **Never** persist a raw absolute path that would disclose the host filesystem layout.

  The same three-case helper is used for `exception_file` and every trace `frame.file`. Documented in privacy section.
- **Wrapped exceptions.** When the captured `Throwable` has a `getPrevious()` chain, include **one** level of previous-exception detail in a `### Previous` sub-section (class, file:line, capped/optionally-redacted message). Deeper chains are not walked — risk of unbounded recursion vs. value drops sharply after the first level. Wrapped traces are not duplicated.
- **Frame "Call" formatting.** Each trace row's "Call" cell is built as `Class::method()` when `class` is present, `function()` when only `function` is present, and `{closure}()` for anonymous closures (which set `function` to `{closure}` natively in PHP backtraces). Constructors render as `Class::__construct()`.

### 2.3 Body shape

```markdown
## Exception

- **Class:** `Illuminate\Validation\ValidationException`
- **File:** `app/Http/Controllers/OrderController.php:142`
- **Message:** The given data was invalid.

### Trace (top 10)

| # | File | Line | Call |
| --- | --- | --- | --- |
| 1 | app/Http/Controllers/OrderController.php | 142 | `OrderController::store()` |
| 2 | vendor/laravel/framework/src/Illuminate/Routing/Controller.php | 54 | `Controller::callAction()` |
...
```

Frame count default `STOPWATCH_LOG_EXCEPTIONS_TRACE_FRAMES=10`. Set `=0` to omit the trace section entirely (some teams just want class+file:line).

### 2.4 Where the exception object comes from

Currently `StopwatchMiddleware::handle()` catches `Throwable $throwable` on the exception path and calls `$this->finishWithContext($request, status: 500, threw: true)`. The exception object is in scope but not passed through.

Add a new context-key `'exception'` whose value is the `Throwable` instance. The recorder reads it from the resolved context, builds the persistable shape (class, file, line, message, trace), and discards the original object.

`withRunContext()` is currently typed `array<string, scalar|null>`. Loosening to `array<string, mixed>` would defeat the YAML frontmatter contract. Instead: introduce a parallel **non-persistable** "transient" context bucket on `Stopwatch` for objects-the-recorder-needs-but-we-won't-persist:

```php
public function withTransientContext(string $key, mixed $value): self;
public function transientContext(string $key): mixed;
```

Recorders pull the exception via `$stopwatch->transientContext('exception')` — never via the persisted `$context` array passed to `record()`. Transient context is cleared in `reset()` and after `finish()` dispatch (same lifecycle as `$runContext`).

This keeps the public `RunRecorder::record(Stopwatch, array $context)` contract scalar-only and pushes the object-passing channel through the Stopwatch instance.

---

## 3. Laravel Context

### 3.1 What's captured

`Illuminate\Support\Facades\Context` (Laravel 11+) is a per-request key/value bag that auto-propagates to logs and queued jobs. Apps already invest in setting `trace_id`, `tenant_id`, `user_id`, etc. We capture `Context::all()` once at finish time.

| Source value type           | Body                                        | Frontmatter (only if promoted)        |
|-----------------------------|---------------------------------------------|---------------------------------------|
| Scalar / null               | `<key>` row, value as-is                    | `ctx_<key>: <value>` (scalar only)    |
| Array / object              | `<key>` row, JSON-encoded (`JSON_UNESCAPED_SLASHES`); per-value byte cap (§3.7); fallback to `<resource>` / `<unencodable>` placeholder when `json_encode` returns `false` | Skipped (only scalars promote)        |
| Hidden context              | **Never captured** (`Context::allHidden()` not read) | **Never captured**                    |

**Frontmatter prefix.** Promoted keys are prefixed with `ctx_` (e.g. `ctx_trace_id`) to namespace them away from existing frontmatter fields. Body keys appear without the prefix.

**Promotion** is opt-in via `options.context.frontmatter_keys = ['trace_id', 'tenant_id']`. Promoted values:
- must be scalar (string / int / float / bool / null) — non-scalars at a frontmatter-key are silently skipped (debug-log only) and still appear in the body;
- must serialise to ≤256 chars after the round-trip-safe encoder (§3.1a) — longer values are skipped with a debug-log entry to keep frontmatter parsing cheap;
- contribute to a total promoted-frontmatter byte budget — once the cumulative encoded length of all promoted `ctx_*` lines exceeds 2048 bytes, further promotions are dropped (debug-logged). Combined with bumping `RunLogReader::FRONTMATTER_READ_BYTES` from `4096` to `8192` (cheap on first-block I/O, future-proof against drift), this keeps list/filter behaviour reliable even with many small promoted keys.

### 3.1a Round-trip-safe encoding for `ctx_*` values

The existing `ScalarCodec` (`src/RunLog/ScalarCodec.php`) auto-decodes `null` / `true` / `false` / numeric-looking strings on read. That is correct for *known-typed* fields like `duration_ms` (which the writer emits as an int and the reader expects as an int), but it breaks for *user-supplied* string values that happen to look like one of those literals — `Context::add('trace_id', 'true')` would round-trip as `bool true`; `Context::add('user_code', '01')` would round-trip as `int 1`.

**Mitigation:** extend the codec with a *string-safe* encoder + a quoted-string decoder.

- **Encoder.** A new `ScalarCodec::encodeStringSafe(string|int|float|bool|null $value): string` is used for promoted `ctx_*` values only. Behaviour:
  - `null` / `bool` / int / float → same as `encode()` (these come from the writer side as their own type, not as user strings).
  - String values that match `/^(null|true|false|-?\d+(\.\d+)?)$/i` OR start/end with whitespace → wrap in single quotes and escape inner single quotes (`'` → `''`, YAML-1.1-compatible).
  - All other strings → unwrapped (back-compat with already-emitted frontmatter).
- **Decoder.** Extend `decode()`: if the trimmed value starts and ends with `'`, strip the outer quotes, unescape `''` → `'`, return the unwrapped string verbatim (no further coercion). Existing literal coercion paths are unchanged.

The existing built-in frontmatter fields are untouched — they continue to use `encode()` and rely on type-known reads.

This change ships as part of Phase 2 of this spec (small, self-contained, well-tested) so the round-trip safety lands before any `ctx_*` promotion is exposed to users.

### 3.2 Allow/deny + type policy

Two filtering knobs, applied in order:

1. **`options.context.allow`** — list of keys. Empty = include all visible *scalar* keys (see type policy below). Non-empty = include exactly these keys regardless of value type.
2. **`options.context.deny`** — list of keys. Always applied after `allow`. Useful for "include all except these few".

**Type policy.** This is the security difference from a naive Debugbar `'masked' => []` clone:

- **`allow=[]`** (the default once `collect_context=true`) — only **scalar** visible keys are captured. Arrays / objects / resources are skipped with a debug-log entry: `Context key 'foo' skipped — non-scalar values must be explicitly listed in run_log.options.context.allow`. Apps that have stuffed an Eloquent model into `Context::add('user', $user)` get safe behaviour by default — the rich object is not silently JSON-dumped to disk.
- **`allow=['user']`** (explicit allowlist) — the named keys are captured regardless of type, then JSON-encoded for non-scalars (still subject to the per-value byte cap §3.3b).

`allow=[]` + `deny=[]` (defaults) → capture all visible **scalar** Context keys.

`allow=['trace_id','tenant_id']` → capture only those two (any type).

`allow=[]` + `deny=['credit_card_last4']` → capture all visible **scalar** keys except that one.

`allow=['user']` + `mask=['user']` → capture the user key, mask the value.

### 3.3 Masking

`options.context.mask` — list of keys whose **value** is replaced with `***` while the key itself is preserved. Useful when you want to know "this key was set" without leaking the value.

### 3.3a Empty context

If after `allow` / `deny` / `mask` the captured map is empty, omit the `## Context` section entirely from the body — parallel to how empty SQL / HTTP detail tables are not emitted. No `ctx_*` frontmatter fields are written either.

### 3.3b Per-value body cap

Each non-scalar value is JSON-encoded for the body. The serialised string is capped at `STOPWATCH_LOG_CONTEXT_VALUE_MAX_BYTES` (default `4096` bytes) — values exceeding the cap are truncated and a `… (truncated, original N bytes)` suffix is appended. Scalar values are subject to the same byte cap to bound runaway-string keys (e.g. a megabyte of JSON stuffed into a single `Context::add()` call).

### 3.4 Hidden context

Laravel separates `Context::add()` (visible, propagates to logs) from `Context::addHidden()` (request-scoped only, never serialized). Run log respects the same boundary: hidden context is never persisted, regardless of `allow`/`deny`. Documented in privacy section.

### 3.5 Body shape

```markdown
## Context

| Key | Value |
| --- | --- |
| `trace_id` | `01HZ8K9X4N5P2Q3R4S5T6U7V8W` |
| `tenant_id` | `acme-corp` |
| `user_id` | `42` |
| `feature_flags` | `{"new_billing":true,"beta_search":false}` |
| `credit_card_last4` | `***` |
```

Promoted keys also appear in frontmatter:

```yaml
---
id: 01HZAA...
recorded_at: 2026-04-29T...
ctx_trace_id: 01HZ8K9X4N5P2Q3R4S5T6U7V8W
ctx_tenant_id: acme-corp
---
```

### 3.6 Cross-version + boot-state handling

`Illuminate\Support\Facades\Context` lands in Laravel 11. Stopwatch supports `^11.0|^12.0|^13.0`, so the class is always present — `class_exists()` is **not** the gate. The real concern is *facade boot state*: in early-boot, console-only, or test-harness paths the facade root may not be resolvable yet (`Context::all()` would call into an unresolved container).

The collector wraps the call in `try/catch (\Throwable)` and treats any failure (resolution error, not-yet-booted, deliberate test isolation) the same way: log once via `logger()->warning()` and write the run-log file without the `## Context` section. No exception ever propagates out of the recorder (matches the existing `MarkdownRunRecorder::reportFailure()` pattern).

---

## 4. Configuration

Append to `config/stopwatch.php` under the existing `run_log` block:

```php
'run_log' => [
    'enabled' => (bool) env('STOPWATCH_LOG_RUNS', false),
    'path' => env('STOPWATCH_LOG_DIR'),
    'min_duration_ms' => (int) env('STOPWATCH_LOG_MIN_DURATION_MS', 50),
    'max_files' => (int) env('STOPWATCH_LOG_MAX_FILES', 200),
    'max_age_days' => (int) env('STOPWATCH_LOG_MAX_AGE_DAYS', 7),
    'detail' => env('STOPWATCH_LOG_DETAIL', 'summary'),
    'include_bindings' => (bool) env('STOPWATCH_LOG_INCLUDE_BINDINGS', false),
    'skip_empty' => (bool) env('STOPWATCH_LOG_SKIP_EMPTY', true),

    // NEW — collector toggles
    'collect_exceptions' => (bool) env('STOPWATCH_LOG_COLLECT_EXCEPTIONS', true),
    'collect_context' => (bool) env('STOPWATCH_LOG_COLLECT_CONTEXT', false),

    // NEW — collector tuning
    'options' => [
        'exceptions' => [
            'message' => (bool) env('STOPWATCH_LOG_EXCEPTIONS_MESSAGE', false),
            'message_max_chars' => (int) env('STOPWATCH_LOG_EXCEPTIONS_MESSAGE_MAX_CHARS', 500),
            'mask_message_matching' => [],   // list<string> regex|substring patterns
            'trace_frames' => (int) env('STOPWATCH_LOG_EXCEPTIONS_TRACE_FRAMES', 10),
            'trace_exclude_paths' => [],     // list<string> substring matches against frame.file
        ],
        'context' => [
            'allow' => [],                   // list<string> — empty = all visible keys
            'deny' => [],                    // list<string> — applied after allow
            'mask' => [],                    // list<string> — replace value with *** but keep key
            'frontmatter_keys' => [],        // list<string> — promote scalar values to frontmatter as `ctx_<key>`
            'value_max_bytes' => (int) env('STOPWATCH_LOG_CONTEXT_VALUE_MAX_BYTES', 4096),
        ],
    ],
],
```

### 4.1 Defaults — rationale

- **`collect_exceptions = true`** — when the run log is enabled at all, the user wants to debug. Knowing what crashed is the floor of usefulness. The PII risk is gated separately on `message`.
- **`exceptions.message = false`** — most apps will want the class + file + line first, then opt into messages once they verify the source paths don't leak.
- **`collect_context = false`** — Context is opt-in because (a) many apps don't use it yet; (b) untyped Context values can blow up file size if someone stuffs an Eloquent model in. User explicitly enables.
- **`context.allow = []`** (capture all visible) once enabled — Debugbar shape: empty list = no extra restrictions. Hidden Context is the user's pre-existing privacy boundary.
- **`context.frontmatter_keys = []`** — body-only by default. Promoting keys is a deliberate decision (changes list sort surface, requires scalar values).

### 4.2 Env naming convention

Hierarchical, mirrors config path:

```
STOPWATCH_LOG_COLLECT_EXCEPTIONS         → run_log.collect_exceptions
STOPWATCH_LOG_EXCEPTIONS_MESSAGE         → run_log.options.exceptions.message
STOPWATCH_LOG_EXCEPTIONS_MESSAGE_MAX_CHARS → run_log.options.exceptions.message_max_chars
STOPWATCH_LOG_EXCEPTIONS_TRACE_FRAMES    → run_log.options.exceptions.trace_frames
STOPWATCH_LOG_COLLECT_CONTEXT            → run_log.collect_context
```

Array-typed options (`mask_message_matching`, `allow`, `deny`, `mask`, `frontmatter_keys`, `trace_exclude_paths`) are **config-only** — env can't express arrays cleanly. Document this. Users who want array tuning publish the config (`php artisan vendor:publish --tag=stopwatch-config`).

---

## 5. Code structure

### 5.1 New classes

```
src/RunLog/
├── ExceptionDetail.php       # builds frontmatter scalars + body section from a Throwable
├── ExceptionDetailRenderer.php  # body markdown for the ## Exception section
├── ContextCapture.php        # reads Context::all(), applies allow/deny/mask/promote
└── ContextCaptureRenderer.php   # body markdown for the ## Context section
```

`MarkdownRunRecorder` consumes these via constructor injection (or constructed inline from config).

### 5.2 `Stopwatch` additions

```php
/** Magic-string-free key for the captured exception. Used by middleware + recorder. */
public const string TRANSIENT_EXCEPTION = 'exception';

/** @var array<string, mixed> Transient (object-bearing) context cleared on reset() and after finish() dispatch. */
private array $transientContext = [];

public function withTransientContext(string $key, mixed $value): self;
public function transientContext(string $key): mixed;  // returns null if key missing
```

Cleared in `reset()` AND at end of `finish()` (same lifecycle as `$runContext`).

The const lets middleware and the recorder share a typo-proof key. No `allTransientContext()` getter — current uses are key-targeted; we can add it if a future caller actually needs map iteration.

### 5.3 `StopwatchMiddleware` exception path

```php
} catch (\Throwable $throwable) {
    $this->stopwatch->withTransientContext(Stopwatch::TRANSIENT_EXCEPTION, $throwable);
    $this->finishWithContext($request, status: 500, threw: true);

    throw $throwable;
}
```

### 5.4 `MarkdownRunRecorder::doRecord()` flow

After existing frontmatter build:

```php
$frontmatter = $this->frontmatterValues($id, $totals, $runContext);

$exception = $this->collectExceptions
    ? $stopwatch->transientContext(Stopwatch::TRANSIENT_EXCEPTION)
    : null;

if ($exception instanceof \Throwable) {
    $frontmatter = [
        ...$frontmatter,
        'exception_class' => $exception::class,
        'exception_file' => $this->relativise($exception->getFile()),
        'exception_line' => $exception->getLine(),
    ];
}

$capturedContext = $this->collectContext
    ? $this->contextCapture->capture()
    : ['frontmatter' => [], 'body' => []];

foreach ($capturedContext['frontmatter'] as $key => $value) {
    $frontmatter['ctx_' . $key] = $value;
}

$body = $stopwatch->toMarkdown();
$body .= $this->detailRenderer->render($stopwatch->checkpoints());

if ($exception instanceof \Throwable) {
    $body .= "\n\n" . $this->exceptionRenderer->render($exception);
}

if ($capturedContext['body'] !== []) {
    $body .= "\n\n" . $this->contextRenderer->render($capturedContext['body']);
}
```

`$runContext` is the recorder-input array (renamed from `$context` to avoid shadowing the locally-captured Context map). `$capturedContext` is initialised even when the collector is off so the body-build block has a stable shape — and the `body !== []` check enforces §3.3a (no `## Context` section when filtered map is empty).

Section ordering: existing toMarkdown → optional SQL/HTTP detail (existing) → Exception (new) → Context (new). Exception sits closer to the profile because crash debugging usually reads top-to-bottom.

### 5.5 ContextCapture API

```php
final readonly class ContextCapture
{
    /**
     * @param list<string> $allow
     * @param list<string> $deny
     * @param list<string> $mask
     * @param list<string> $frontmatterKeys
     */
    public function __construct(
        array $allow,
        array $deny,
        array $mask,
        array $frontmatterKeys,
        int $valueMaxBytes = 4096,
    );

    /**
     * @return array{
     *     frontmatter: array<string, scalar|null>,
     *     body: array<string, scalar|array<mixed>|null>,
     * }
     */
    public function capture(): array;
}
```

Implementation:
1. Try `Context::all()`. On `Throwable` → log once, return `['frontmatter' => [], 'body' => []]`.
2. Apply `allow` (if non-empty, intersect by key).
3. Apply `deny` (always — diff by key).
4. Apply `mask` — replace value with the literal string `***` while preserving the key.
5. Apply per-value byte cap (`$valueMaxBytes`):
   - Scalars: cast to string, `mb_strcut` to cap (byte-precise, codepoint-safe), suffix `… (truncated, original N bytes)` if cut.
   - Arrays / objects: `json_encode` with `JSON_UNESCAPED_SLASHES`. If the result is `false` (resource, circular ref, malformed UTF-8 without `JSON_INVALID_UTF8_SUBSTITUTE`), substitute the placeholder string `<unencodable: <gettype>>`. Apply the same byte cap to the JSON string.
6. Split into `frontmatter` (scalar values whose key is in `frontmatterKeys` AND whose encoded length ≤ 256 chars) + `body` (everything else, including non-scalars at promotion-keys which are debug-logged as "skipped non-scalar promotion").

Wraps the entire flow in `try/catch (\Throwable)` — any failure returns the empty-result shape + a single `logger()->warning()`.

---

## 6. Skill update

Append to `resources/boost/skills/profile-app/SKILL.md`'s Step 6:

```markdown
**For crashed requests** (`threw: true` in frontmatter), the run log includes
the exception class + file:line in the frontmatter and a `## Exception` section
in the body with a top-N stack trace. If `STOPWATCH_LOG_EXCEPTIONS_MESSAGE=true`,
the message itself is included (off by default — messages can leak user input).

**For correlation with structured logs**, set `STOPWATCH_LOG_COLLECT_CONTEXT=true`
to capture `Illuminate\Support\Facades\Context::all()`. The `trace_id`, `tenant_id`,
or `user_id` you set via `Context::add()` will appear in the run-log body so
you can pivot from a slow run to its matching `laravel.log` entry by `trace_id`.

If you want certain context keys to be sortable from the list view, promote them:

    'frontmatter_keys' => ['trace_id', 'tenant_id'],

Then `stopwatch:runs:list` can group/filter on `ctx_tenant_id` once we add that
flag (see "Long-tail polish" in the spec).
```

---

## 7. Privacy & overhead

| Concern | Mitigation |
|---------|------------|
| Exception messages leak user input | `STOPWATCH_LOG_EXCEPTIONS_MESSAGE=false` default; cap at 500 chars; regex/substring `mask_message_matching` |
| Stack-trace args leak request body | We read `$e->getTrace()` and **only** keep `file`/`line`/`class`/`function`/`type` — never `args` |
| Trace paths leak project layout | Project-relative when under `base_path()`; `vendor/<package>/...` when under any `/vendor/` segment outside the project; `<external>/<basename>` fallback for unrelated paths — **never** raw absolute. `trace_exclude_paths` additionally hides noise. |
| Context leaks PII | Hidden Context never captured; `allow`/`deny`/`mask` filter visible Context; `collect_context=false` default; `allow=[]` captures only **scalar** keys (rich objects opt in via explicit allowlist) |
| Context blows up file size | Non-scalar values JSON-encode; `allow` allowlist for narrow capture; document the cost |
| Per-run overhead | Both collectors run at finish only, gated on flags. When off, zero allocation. When on, one `Context::all()` call + one `Throwable::getTrace()` slice — negligible |
| Transient context lifecycle | Cleared in `reset()` AND post-`finish()` dispatch (same as `$runContext`) so a long-lived singleton can't leak the previous run's exception |

---

## 8. Non-goals

- **Per-checkpoint backtrace** — explicitly deferred (high overhead per checkpoint conflicts with the package's "near-zero when off" identity). The Step 6 skill workflow already gives you call-site info iteratively.
- **Cache hits / mailable / notification collectors** — Debugbar territory; out of scope unless users ask.
- **Capturing exceptions from non-middleware paths** automatically — middleware handles request crashes; for queued jobs / commands that throw, users can opt in by calling `$stopwatch->withTransientContext(Stopwatch::TRANSIENT_EXCEPTION, $e)` themselves before `finish()`. Document the pattern.
- **Capturing exceptions through `Stopwatch::dd($e)`** — `dd()` calls `finish()` *before* it inspects its dump arguments (`src/Stopwatch.php:866`), so the recorder dispatches before any throwable in `$args` is reachable. Reordering `dd()` to extract throwables from its args before finishing is a deliberate behaviour change to a public API, deferred until there's user demand. Workaround: `$stopwatch->withTransientContext(Stopwatch::TRANSIENT_EXCEPTION, $e)->dd()`.
- **Sensitive-data detection** (regex auto-redaction of email/credit-card patterns) — false-positive prone, out of scope. `mask` lets users be explicit.
- **Diffing context across runs** — possible later via a separate command.

---

## Implementation

### Phase 1: Stopwatch transient context (Priority: HIGH)

- [x] Add `$transientContext` private array to `Stopwatch`. Methods: `withTransientContext(string, mixed): self`, `transientContext(string): mixed`, `allTransientContext(): array`.
- [x] Clear `$transientContext` in `reset()` and at end of `finish()` (after both dispatch loops, alongside `$runContext`).
- [x] Tests — set/get round-trip, `transientContext('missing-key')` returns `null`, cleared on reset, cleared after finish, survives between checkpoint and finish, multiple keys coexist, mixed-type values (object + array + scalar) all retrievable.

### Phase 2: Round-trip-safe `ScalarCodec` extension + reader-window bump (Priority: HIGH)

- [x] Add `ScalarCodec::encodeStringSafe()` per §3.1a — quote ambiguous string values (literal-looking, leading/trailing whitespace) with single quotes; escape inner `'` as `''`.
- [x] Extend `ScalarCodec::decode()` — strip outer single quotes when present and unescape `''` → `'`; preserve existing literal coercion for unquoted values (full back-compat).
- [x] Bump `RunLogReader::FRONTMATTER_READ_BYTES` from `4096` to `8192`.
- [x] Tests — round-trip of `"true"`, `"false"`, `"null"`, `"01"`, `"1.20"`, `" leading"`, `"trailing "`, strings containing `'`, plain strings (still unquoted), existing typed fields (`duration_ms: 487`) still parse as int (writer side untouched for built-in fields), reader correctly handles 6KB frontmatter (between old and new limits).

### Phase 3: Exception detail collector (Priority: HIGH)

- [x] Create `src/RunLog/ExceptionDetail.php` — pure data builder. Takes `Throwable` + options (`messageEnabled`, `messageMaxChars`, `maskPatterns`, `traceFrames`, `traceExcludePaths`). Returns array shape `{class, file, line, message?, frames: list<{file, line, class?, function, type?}>, previous?: {class, file, line, message?}}`.
- [x] Create `src/RunLog/ExceptionDetailRenderer.php` — markdown renderer for the `## Exception` body section. Header bullets + trace table + optional `### Previous` sub-section. `frames=0` skips the trace section.
- [x] Path relativisation helper with the three-case fallback (project-relative → `vendor/...` → `<external>/<basename>`). Used by both `exception_file` and trace `frame.file`.
- [x] Strip `args` from every frame defensively even though `getTrace()` includes them — never persist call arguments.
- [x] Apply `mask_message_matching` patterns to the message AFTER capping (so masked tokens don't push past the cap). Pattern syntax: leading `/` = preg, otherwise substring.
- [x] Tests — class/file/line in frontmatter, message gated by flag, message capped via `mb_substr` (multi-byte UTF-8 not split mid-codepoint), substring mask redacts, regex mask (`/pattern/` syntax) redacts, mask applied AFTER cap, trace frames respect `trace_frames` cap, `trace_frames=0` omits section, `trace_exclude_paths` filters frames by substring, args never persisted (assert no `args` key on any persisted frame), one level of `getPrevious()` rendered in `### Previous`, deeper previous-chain NOT walked, file paths relativised when under `base_path()`, vendor-fallback applies for paths under any `/vendor/` segment outside `base_path()`, `<external>/<basename>` fallback for unrelated paths (no absolute paths in output), frame "Call" formatting handles `Class::method`, top-level `function`, and `{closure}`.

### Phase 4: Context collector (Priority: HIGH)

- [x] Create `src/RunLog/ContextCapture.php` — reads `Context::all()` (try/catch + log-once on failure), applies `allow` → `deny` → `mask` → split into `frontmatter` + `body` halves per spec §5.5.
- [x] Implement scalar-only default per §3.2 type policy: `allow=[]` skips non-scalar visible keys (debug-logged "non-scalar Context key 'foo' skipped — add to allow to include"); explicit `allow` allowlist captures any type.
- [x] Create `src/RunLog/ContextCaptureRenderer.php` — markdown table for the `## Context` body section. Scalars rendered as-is; arrays/objects JSON-encoded with `JSON_UNESCAPED_SLASHES`.
- [x] Skip `frontmatter_keys` whose values are non-scalar (log a debug message, omit the key).
- [x] Enforce total promoted-frontmatter byte budget (2048 bytes cumulative across all `ctx_*` lines) — once exceeded, further promotions drop with debug-log.
- [x] Use `ScalarCodec::encodeStringSafe()` (Phase 2) for promoted `ctx_*` values so user-supplied strings round-trip safely.
- [x] **Never** read `Context::allHidden()`.
- [x] Tests — empty-allow captures only scalar visible keys (non-scalars dropped + debug-logged), explicit-allow captures any type including arrays/objects, deny excludes, mask replaces value with `***` but keeps key, hidden context never appears, `frontmatter_keys` promote scalars, `frontmatter_keys` silently skip non-scalars, `frontmatter_keys` skip values >256 chars after encode, total promoted-byte budget caps further promotions when exceeded, `ctx_user_id='01'` round-trips as string `'01'` not int 1 (regression for codec extension), per-value byte cap truncates with `… (truncated, original N bytes)` suffix, `json_encode` failure on resource/circular value yields `<unencodable: <gettype>>` placeholder, Context facade not bootable → empty result + warning logged once, post-filter empty map → recorder skips emitting `## Context` section entirely.

### Phase 5: MarkdownRunRecorder integration (Priority: HIGH)

- [x] Inject `ExceptionDetail`, `ExceptionDetailRenderer`, `ContextCapture`, `ContextCaptureRenderer` into `MarkdownRunRecorder` (with feature flags `collectExceptions` / `collectContext` constructor args).
- [x] In `doRecord()`: after computing frontmatter values, merge in exception class/file/line and `ctx_*` promoted keys. Append `## Exception` and `## Context` body sections after the existing detail sections.
- [x] Body section ordering: `toMarkdown()` → optional `## SQL detail` → optional `## HTTP detail` → optional `## Exception` → optional `## Context`.
- [x] Tests — `MarkdownRunRecorderTest` extension: exception persisted when transient context has it, no exception fields when none captured, context section present when enabled, frontmatter promotion works, ordering of sections stable, both collectors stay off when flags are false.

### Phase 6: Middleware exception → transient context (Priority: HIGH)

- [x] In `StopwatchMiddleware::handle()`'s catch block, call `$this->stopwatch->withTransientContext('exception', $throwable)` BEFORE `$this->finishWithContext(...)`.
- [x] Tests — `MiddlewareRunLogTest` extension: when controller throws, run-log file contains `exception_class` in frontmatter and `## Exception` section in body; exception object never appears in the persisted markdown literally (no `Object#123` or class-method-reflection garbage).

### Phase 7: Configuration + service provider wiring (Priority: HIGH)

- [x] Append `collect_exceptions`, `collect_context`, and the `options.exceptions` + `options.context` blocks to `config/stopwatch.php` per §4.
- [x] Update `RunLogServiceRegistrar::register()` to read the new keys and inject them into `MarkdownRunRecorder` (alongside existing `detail`, `include_bindings`, etc.).
- [x] Tests — `ServiceProviderRunLogTest` extension: defaults match spec (`collect_exceptions=true`, `collect_context=false`, `exceptions.message=false`); env override of each scalar config key flows through; array options (`mask_message_matching`, `trace_exclude_paths`, `allow`, `deny`, `mask`, `frontmatter_keys`) flow through from config; bogus types in config (e.g. `mask_message_matching = 'string'` instead of array) degrade gracefully — recorder still constructs without throwing; non-numeric `value_max_bytes` falls back to default.

### Phase 8: Skill + README updates (Priority: MEDIUM)

- [x] Append the **For crashed requests** + **For correlation with structured logs** paragraphs to `resources/boost/skills/profile-app/SKILL.md` Step 6.
- [x] Add a row to the env-knobs table for each new env var.
- [x] Add a "Run log: exception detail and Laravel Context" subsection to `README.md` under the existing run-log section, with a worked example showing `STOPWATCH_LOG_COLLECT_CONTEXT=true` + Context propagation.
- [x] Verify boost sync (`vendor/bin/testbench package-boost:sync`) keeps `.claude/` / `.github/` files in lockstep.

### Phase 9: Release notes + pre-release suite (Priority: MEDIUM)

- [x] Write `RELEASE_NOTES_<next-version>.md` summarising the two collectors, the new env vars, and the privacy defaults. Pin verified-sha after CI green.
- [x] Run the full pre-release gauntlet: rector / pint / phpstan / phpunit / boost-sync.

### Phase 10: Long-tail polish (Priority: LOW)

- [x] `stopwatch:runs:list` flags: `--exception-class=Foo` (matches FQCN or trailing class name); `--ctx KEY=VALUE` (repeatable, AND-semantics) to filter on promoted context keys.
- [~] `stopwatch:runs:show <id>` could optionally hyperlink frame paths via the `editor` config knob (Debugbar pattern: `phpstorm://`, `vscode://`, etc.) for terminals that render OSC-8 hyperlinks. **Skipped** — see Findings.
- [x] Docs: "Pivoting between run-log and laravel.log" recipe showing `Context::add('trace_id', Str::ulid())` → run-log has `ctx_trace_id` → grep `laravel.log` for the same id (added to README + skill).

---

## Open Questions

1. **Should `collect_exceptions=true` capture exceptions thrown OUTSIDE the middleware** (e.g. queued jobs that catch their own exceptions and call `Stopwatch::finish()`)? Spec assumes no — middleware is the only built-in setter. Document the manual `withTransientContext(Stopwatch::TRANSIENT_EXCEPTION, $e)` pattern for queue/command users.

2. **Should `trace_exclude_paths` ship with sensible defaults** (e.g. `vendor/laravel/framework/src/Illuminate/Routing`)? Debugbar ships empty defaults and lets users opt in. We could ship a small `STOPWATCH_VENDOR_TRACE_DEFAULTS` const that users can opt into via a flag, separately. Probably defer — empty-default is honest.

---

## Resolved Questions

1. **Should `frontmatter_keys` enforce a length cap on the value before promoting?** **Decision:** yes — 256 chars after `ScalarCodec::encode()`. Non-scalars already skipped. **Rationale:** frontmatter parsing reads the first ~1KB of each file; a single megabyte-sized promoted value would force readers to crack the cap or silently truncate. 256 chars covers ULIDs, slugs, IDs, short names — the legitimate promote candidates.

2. **Should `Throwable::getPrevious()` be walked?** **Decision:** include exactly one previous-exception level in a `### Previous` sub-section (class, file:line, capped/maskable message). Do NOT walk deeper. **Rationale:** wrapped exceptions are common in Laravel (handler wraps validator, exception handler wraps handler, etc.). One level of unwrap is high-value for "what really happened"; chains beyond that are vanishingly rare and the recursion risk is real.

3. **Format of `exception_file` in frontmatter — relative or absolute?** **Decision:** relative to `base_path()` when that resolves AND the absolute path starts with the base prefix; otherwise absolute. Same helper used for trace `frame.file`. **Rationale:** relative paths are portable across machines (matters for archived run-logs and CI artifacts) and grep-friendly; absolute is the honest fallback when the base prefix doesn't match (e.g. a vendored file resolved through a symlink).

---

## Findings

<!-- Notes added during implementation. Do not remove this section. -->

### Phase 10 — OSC-8 editor hyperlinks (skipped)

`stopwatch:runs:show <id>` outputs the markdown file verbatim line-by-line. Wrapping
trace `frame.file` paths in OSC-8 escapes (`\033]8;;phpstorm://open?file=...\033\\…`)
would require:

1. A new `stopwatch.run_log.editor` config knob (mirroring Debugbar's `editor`).
2. Either parsing the markdown table cells in `RunsShowCommand` (fragile — string
   matching against `| N | path/file.php | line |`) or re-architecting
   `ExceptionDetailRenderer` so it emits hyperlinks at render time (couples a pure
   markdown renderer to a terminal-output concern).

OSC-8 support in terminals is partial (iTerm2, WezTerm, Kitty yes; macOS Terminal
no), so users on un-supported terminals see noisy escape sequences in the file
content. The existing `<file>:<line>` format is already pasteable into editors
that support `phpstorm://` URL handlers via shell helpers — users who want
hyperlinks can wrap the show output themselves.

Defer until users specifically ask. Empty-default-is-honest matches the `0.7.0`
stance on `trace_exclude_paths`.
