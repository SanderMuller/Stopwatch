# Configuration reference

Publish the annotated config with `php artisan vendor:publish --tag=stopwatch-config`, or set the env vars below. The file itself is [`config/stopwatch.php`](https://github.com/SanderMuller/Stopwatch/blob/main/config/stopwatch.php).

## Core

| Setting | Env | Default | Effect |
|---|---|---|---|
| `enabled` | `STOPWATCH_ENABLED` | `true` | `false` makes every call a no-op |
| `output` | `STOPWATCH_OUTPUT` | `silent` | default mode: `silent`, `log`, `stderr`, `dump` |
| `log_level` | `STOPWATCH_LOG_LEVEL` | `debug` | level used when output is `log` |
| `slow_threshold` | `STOPWATCH_SLOW_THRESHOLD` | `50` | ms above which a checkpoint reads as slow |

## Per feature

| Feature | Env vars |
|---|---|
| [Tracking](04-tracking.md) | `STOPWATCH_TRACK_QUERIES`, `STOPWATCH_TRACK_MEMORY`, `STOPWATCH_TRACK_HTTP` |
| [Notifications](10-notifications.md) | `STOPWATCH_NOTIFY_THRESHOLD`, `STOPWATCH_MAIL_TO`, `STOPWATCH_MAIL_SUBJECT` |
| [Toolbar](06-profiler-toolbar.md) | `STOPWATCH_INJECT`, `STOPWATCH_INJECT_ENVIRONMENTS`, `STOPWATCH_INJECT_POSITION`, `STOPWATCH_INJECT_SLOW_REQUEST_MS` |

## Run log

| Env | Default | Effect |
|---|---|---|
| `STOPWATCH_LOG_RUNS` | `false` | master toggle |
| `STOPWATCH_LOG_DIR` | `storage/stopwatch/runs` | storage path |
| `STOPWATCH_LOG_MIN_DURATION_MS` | `50` | skip faster runs; `0` logs everything |
| `STOPWATCH_LOG_DETAIL` | `summary` | `full` appends per-call SQL and HTTP tables |
| `STOPWATCH_LOG_INCLUDE_BINDINGS` | `false` | persist SQL bindings in `full` mode — **PII opt-in** |
| `STOPWATCH_LOG_EXCEPTIONS_MESSAGE` | `false` | persist `$e->getMessage()` — **PII opt-in** |
| `STOPWATCH_LOG_COLLECT_CONTEXT` | `false` | capture visible `Context::all()` |

<details>
<summary>Retention, exception and context knobs</summary>

| Env | Default | Effect |
|---|---|---|
| `STOPWATCH_LOG_MAX_FILES` | `200` | retained files; oldest pruned |
| `STOPWATCH_LOG_MAX_AGE_DAYS` | `7` | soft age cap, probabilistic prune |
| `STOPWATCH_LOG_SKIP_EMPTY` | `true` | skip runs with zero checkpoints |
| `STOPWATCH_LOG_COLLECT_EXCEPTIONS` | `true` | capture class, file, line and trace |
| `STOPWATCH_LOG_EXCEPTIONS_MESSAGE_MAX_CHARS` | `500` | codepoint cap before `…` |
| `STOPWATCH_LOG_EXCEPTIONS_TRACE_FRAMES` | `10` | frame cap; `0` omits the trace |
| `STOPWATCH_LOG_CONTEXT_VALUE_MAX_BYTES` | `4096` | per-value byte cap |

Array-typed options live under `run_log.options` in the config file, since env cannot express arrays:

| Path | Effect |
|---|---|
| `exceptions.mask_message_matching` | patterns replaced with `***`; leading `/` is a preg, otherwise substring. Applied after the cap |
| `exceptions.trace_exclude_paths` | substring match on `frame.file`, to hide vendor noise |
| `context.allow` | allowlist; empty means all visible **scalar** keys. Rich objects need listing |
| `context.deny` | denylist, applied after allow |
| `context.mask` | replace the value with `***`, keep the key |
| `context.frontmatter_keys` | promote scalars to `ctx_<key>` frontmatter, sortable from the list view |

</details>
