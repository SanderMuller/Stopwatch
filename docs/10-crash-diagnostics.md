# Crash diagnostics

When a request throws, the middleware persists the run with `threw: true`, then re-throws. Frontmatter carries the exception class, file and line; the body gets an `## Exception` section with a capped stack trace and one level of `### Previous` for wrapped exceptions.

```yaml
---
id: 01HZ8K9X4N5P2Q3R4S5T6U7V8W
url: /admin/users
threw: true
exception_class: Illuminate\Validation\ValidationException
exception_file: app/Http/Controllers/OrderController.php
exception_line: 142
ctx_trace_id: 01HZULID0000000000000000A
---
```

**Stack-trace `args` are never persisted:** only `file`, `line`, `class`, `function` and `type` per frame. **The exception message is off by default**, because application messages routinely quote validation input or user data. Turn it on with `STOPWATCH_LOG_EXCEPTIONS_MESSAGE=true`; it is then capped by `STOPWATCH_LOG_EXCEPTIONS_MESSAGE_MAX_CHARS` and can be redacted through `options.exceptions.mask_message_matching`.

For a job or command that catches its own exception, hand it over before `finish()`:

```php
try {
    // ...
} catch (Throwable $e) {
    stopwatch()
        ->withTransientContext(Stopwatch::TRANSIENT_EXCEPTION, $e)
        ->finish();

    throw $e;
}
```

## Correlate with `laravel.log`

`STOPWATCH_LOG_COLLECT_CONTEXT=true` captures `Context::all()` into a `## Context` section. **Hidden context (`Context::addHidden()`) is never read.**

Promote the keys you filter on so they reach frontmatter:

```php
// config/stopwatch.php → run_log.options.context
'frontmatter_keys' => ['trace_id', 'tenant_id'],
```

They land as `ctx_trace_id` / `ctx_tenant_id`, round-trip safe (`"01"` stays a string), and `--ctx key=value` can filter on them. From there, pivot to the log:

```bash
TRACE_IDS=$(php artisan stopwatch:runs:list --threw --exception-class=ValidationException \
    --ctx tenant_id=acme --format=json | jq -r '.[].frontmatter.ctx_trace_id')

for id in $TRACE_IDS; do grep "$id" storage/logs/laravel.log; done
```
