# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> This file is auto-populated by CI on release. To document a change for an
upcoming release, add it to `RELEASE_NOTES_<version>.md` at the repo root —
the release workflow promotes it into this file as part of the tag flow.

## [v0.7.0](https://github.com/SanderMuller/Stopwatch/compare/v0.6.1...v0.7.0) - 2026-04-29

### Run log

Persist every finished stopwatch run as a markdown file under `storage/stopwatch/runs/<ULID>.md` so you (or an AI assistant) can later inspect slow runs without re-running the workload. Off by default — enable with one env var:

```dotenv
STOPWATCH_LOG_RUNS=true

```
Each persisted file is plain markdown (the same shape as `stopwatch()->toMarkdown()`) with a YAML frontmatter header (`id`, `recorded_at`, `duration_ms`, `url`, `method`, `status`, `command`, query/HTTP/memory totals, slow-threshold flag) so listing is cheap. Three artisan commands are registered:

```bash
php artisan stopwatch:runs:list --slow --limit=10
php artisan stopwatch:runs:show <id>
php artisan stopwatch:runs:clear              # cleanup when done

```
Filter the list with `--slow` (only runs that exceeded `slow_threshold`) or `--threw` (only runs whose request crashed mid-flight). The `clear` command supports `--keep=N`, `--days=N`, and `--force` (all destructive paths prompt for confirmation unless `--force` is passed or the shell is non-interactive).

#### What's tracked

The run log captures whole-run totals — including work that happens **after the last checkpoint** — via new accumulators added to `Stopwatch`. The existing `toMarkdown()` body is unchanged. A new public `Stopwatch::finalRunTotals()` exposes the post-finish totals if you want to consume them in your own tooling, and `Stopwatch::checkpoints()` returns a `list<StopwatchCheckpoint>` snapshot.

`StopwatchMiddleware` was extended to record runs even when the controller throws — the run-log file is written with `threw: true` in the frontmatter, then the exception is re-thrown unchanged. This means slow-then-crashing requests are still debuggable.

#### Knobs

| Env var                            | Default     | Purpose                                                              |
|------------------------------------|-------------|----------------------------------------------------------------------|
| `STOPWATCH_LOG_RUNS`               | `false`     | Master toggle                                                        |
| `STOPWATCH_LOG_DIR`                | `storage/stopwatch/runs` | Override the storage path                                |
| `STOPWATCH_LOG_MIN_DURATION_MS`    | `50`        | Skip runs faster than this (set `0` to log everything)               |
| `STOPWATCH_LOG_MAX_FILES`          | `200`       | Hard cap on retained files (deterministic prune on every write)      |
| `STOPWATCH_LOG_MAX_AGE_DAYS`       | `7`         | Soft age cap (5%-probabilistic prune by ULID timestamp)              |
| `STOPWATCH_LOG_DETAIL`             | `summary`   | `summary` or `full` (full appends per-call SQL/HTTP detail tables)   |
| `STOPWATCH_LOG_INCLUDE_BINDINGS`   | `false`     | Persist SQL bindings (off by default — PII opt-in)                   |
| `STOPWATCH_LOG_SKIP_EMPTY`         | `true`      | Skip zero-checkpoint runs (typical for autoStart on idle routes)     |

The run-log directory is auto-created on first write with a `.gitignore` (`*.md`) so the files never accidentally end up in commits. Writes use a tmp-file + atomic `rename()` so concurrent listings can't observe partial files. Recorder failures never propagate — disk errors are logged via `logger()->warning()` and the request completes normally.

#### Skill update

The bundled `profile-app` skill (used by Claude Code via `laravel/boost`) gains a new **Step 6 — Browse-and-debug from a run log** section that walks an AI assistant through enabling the log, reproducing, listing slow runs, and inspecting individual files via the artisan commands.

#### Limitations

The run log is Laravel-only and is **not supported under Laravel Octane / Swoole** in v1 — the `Stopwatch` singleton has mutable per-run state that is not safe for concurrent coroutines. Per-request stopwatch lifecycle is a separate, larger refactor.

#### Public API additions

- `Stopwatch::recordRunsTo(RunRecorder ...$recorders): self` — replace the recorder list.
- `Stopwatch::withRunContext(array $context): self` — merge per-run context (cleared on `reset()` and after `finish()` dispatch).
- `Stopwatch::pushRunContextProvider(callable $provider): self` — register a persistent context provider, evaluated lazily at finish.
- `Stopwatch::resolveRunContext(): array` — merged context (provider output + per-run overrides).
- `Stopwatch::finalRunTotals(): array` — whole-run totals (queries, HTTP, memory, slow-threshold flag).
- `Stopwatch::checkpoints(): list<StopwatchCheckpoint>` — immutable snapshot of recorded checkpoints.
- `RunLog\RunRecorder` interface for custom sinks (e.g. S3, Loki, Slack).

Notification channel dispatch is now wrapped in `try/catch` internally (a throwing channel previously aborted `finish()` before any subsequent dispatch). No behaviour change for non-throwing channels.

Safe to upgrade from 0.6.x with no code changes required.

**Full Changelog**: https://github.com/SanderMuller/Stopwatch/compare/v0.6.1...v0.7.0

## [v0.6.1](https://github.com/SanderMuller/Stopwatch/compare/v0.6.0...v0.6.1) - 2026-04-28

### AI assistant skill

This release ships a Claude Code [skill](https://docs.claude.com/en/docs/claude-code/skills) at `resources/boost/skills/profile-app/SKILL.md` that teaches an AI assistant how and when to reach for `stopwatch()` to investigate a slow request, command, or code path: checkpoint placement, when to enable query / memory / HTTP tracking, how to read the rendered card, and how to wire production tripwires.

If you use [`laravel/boost`](https://github.com/laravel/boost), the skill is auto-discovered from `vendor/sandermuller/stopwatch/resources/boost/skills/` — run `php artisan boost:install`

**Full Changelog**: https://github.com/SanderMuller/Stopwatch/compare/v0.6.0...v0.6.1

## [v0.6.0](https://github.com/SanderMuller/Stopwatch/compare/v0.5.2...v0.6.0) - 2026-04-28

### Added

- **`withHttpTracking()`** — captures outbound `Http::` facade calls per checkpoint with count, duration, and per-call detail (method, URL, status, individual transfer time). Per-call URLs are stripped of query strings at capture time so secrets in URLs don't leak through `toArray()` / `toJson()` / notifications. Up to 50 call detail rows are stored per checkpoint to bound memory; the count and total time still reflect every call beyond that. Status codes are color-coded in the render (green 2xx, amber 4xx, red 5xx + connection failures). Falls back to `RequestSending` → response wall-clock when `transferStats` is absent (e.g. `Http::fake()`) or when a `ConnectionFailed` ends the request, so timeouts no longer report as `0ms`. Configurable via `STOPWATCH_TRACK_HTTP=true`. **Limitation:** only requests through Laravel's `Http::` facade are captured — direct `new GuzzleHttp\Client` instances bypass Laravel's event dispatcher and are not tracked, same as Telescope.
  
- **Per-query SQL + bindings** captured by `withQueryTracking()` — every query's `sql` text, `bindings`, and individual `durationMs` are stored alongside the existing count/total time, capped at 50 per checkpoint. Surfaced in the new click-to-expand modal so you can see *which* query was slow, not just how many.
  
- **Click any row to expand** into a centered modal with the full label, all metadata, memory now/Δ/peak, every captured query (SQL + bindings + per-query duration), and every captured HTTP call. Backdrop click, ESC, or × button closes; only one row open at a time. Animated open (140ms backdrop fade + 180ms card slide-up). Pointer-cursor on rows is JS-gated, so the markup stays email-safe.
  
- **`when()` / `unless()`** conditional helpers on `Stopwatch` for fluent tracking opt-in chains:
  
  ```php
  stopwatch()
      ->withMemoryTracking()
      ->when($trackQueries, fn ($sw) => $sw->withQueryTracking())
      ->unless(app()->runningUnitTests(), fn ($sw) => $sw->withHttpTracking())
      ->start();
  
  
  
  ```
- **Footer totals** now include the cumulative HTTP count + duration alongside queries + memory.
  
- **Markdown summary** (`stopwatch()->toMarkdown()`) gains an HTTP totals line and an HTTP column in the per-checkpoint table when tracking is enabled.
  

### Changed

- **Per-row chips** are suppressed when their tracked count is zero — quiet rows on a tracking-enabled profile no longer carry visually noisy `0q · 0ms` placeholders. Footer totals still show cumulative across all rows.
- **Per-row chips** now render as `[icon] count · time` instead of `count<letter> · time` — the icon (db / globe) carries the meaning, removing the redundant `q` / `h` letter. Footer totals adopt the same shape.
- **Modal in dark mode** is now visually elevated above the page surface via dedicated `--sw-modal-bg` / `--sw-modal-border` / `--sw-modal-divider` / `--sw-modal-text` CSS variables. The previous shared `--sw-bg` / `--sw-border` / `--sw-text` made the modal blend into the dark page; the new variables also prevent slow-row hover overrides (which pin `--sw-text` dark for the pink hover-bg) from leaking into the modal text.
- **Backdrop opacity** strengthened from `rgba(15,23,42,.55)` to `rgba(0,0,0,.65)` — more visible darkening of the rows behind the modal in both color schemes.
- **Connection-failed HTTP requests** now report their actual elapsed time (recovered from the matching `RequestSending` event) instead of always `0ms`.

### Internal

- Render code split into per-tracker helper classes — `StopwatchHttpRenderer`, `StopwatchQueryRenderer`, `StopwatchExpansionRenderer`, `StopwatchSlowStyling` — to keep per-class cognitive complexity within the project's PHPStan budget. All marked `@internal` with non-stable output.
- HTTP listener registration is idempotent (guarded by a registered-once flag), and re-calling `withHttpTracking()` mid-run resets pending state cleanly. The same fix landed for `withQueryTracking()` and the new per-query detail buffer.
- Test suite grew from 81 to 104 tests (303 assertions), including coverage for HTTP tracking lifecycle, per-query SQL capture, the 50-cap behavior, listener idempotency, modal expansion render, and dark-mode CSS variable wiring.

**Full Changelog**: https://github.com/SanderMuller/Stopwatch/compare/v0.5.2...v0.6.0

## [v0.5.2](https://github.com/SanderMuller/Stopwatch/compare/0.5.1...v0.5.2) - 2026-04-26

### Internal

- `CHANGELOG.md` cleanup: removed duplicate `[0.5.0]` and `[0.5.1]` sections that the auto-changelog workflow had appended at end-of-file (instead of prepending above the latest entry) when the file contained an empty `## [Unreleased]` heading. Removed the `[Unreleased]` heading itself so future releases insert at the correct position automatically.

No functional or API changes. Safe to upgrade from 0.5.1 with no code changes required.

**Full Changelog**: https://github.com/SanderMuller/Stopwatch/compare/0.5.1...v0.5.2

## [0.5.1](https://github.com/SanderMuller/Stopwatch/compare/v0.5.0...0.5.1) - 2026-04-26

### Added

- **`profile-app` AI skill** shipped via `package-boost` to downstream Laravel projects. The skill activates when developers ask their AI assistant about slow requests, query counts, memory usage, or performance bottlenecks. It walks through choosing an entry point (middleware vs `measure()` vs manual checkpoints), placing checkpoints at decision boundaries, reading the rendered HTML output, and catching slow paths in production via `notifyIfSlowerThan()`. Auto-synced into `.claude/skills/` and `.github/skills/` so every consumer sees it without extra setup.

## [0.5.0](https://github.com/SanderMuller/Stopwatch/compare/v0.4.2...v0.5.0) - 2026-04-26

### Added

- `Stopwatch::toMarkdown(): string` — Markdown summary table covering total duration, per-checkpoint deltas, and per-checkpoint query/memory metrics when tracking is enabled. The HTML render now ships with a clipboard button in the header that copies the same output, so you can paste a profile into a chat with an AI assistant or a bug report without screenshots.
- `Stopwatch::formatDuration(float $ms): string` — compact human-readable duration formatter that scales the unit so long profiles stay readable: `3.4ms`, `143ms`, `1.25s`, `1m 5s`. Used internally by the HTML render and by `totalRunDurationReadable()` / `timeSinceLastCheckpointReadable()`, and exposed as a public helper.
- `StopwatchCheckpointCollection::totals(): array` — aggregates `queries`, `queryMs`, and `memoryDelta` across all recorded checkpoints, with `hasQueries` / `hasMemory` flags so callers can distinguish "tracking on, zero results" from "tracking off".
- HTML render: cumulative query/memory totals in the footer when the matching tracking is enabled.
- HTML render: empty state when no checkpoints have been recorded.
- HTML render: light/dark theme — respects `prefers-color-scheme` and exposes a header toggle button that persists the user's choice in `localStorage` under the `sw-theme` key. The toggle is hidden when JavaScript is unavailable (graceful degradation in email clients).
- HTML render: keyboard-accessible rows and overview-bar segments (`tabindex`, `:focus-visible` parity with `:hover`, `aria-label` on segments and the slow pill).
- HTML render: `@media print` styles strip interactive chrome and let the card flow naturally for PDF exports.
- HTML render: themable surfaces are exposed as CSS variables on `.sw-stopwatch` (e.g. `--sw-bg`, `--sw-text`, `--sw-border`, `--sw-hover-bg`, `--sw-tip-bg`) for downstream re-skinning.
- `role="region"` and `aria-label="Stopwatch profile"` on the card root.

### Changed

- HTML render redesigned. Each row now shows a colored bar + share %, a stacked metric column (delta / queries / memory), inline metadata chips, and a per-row hover/focus tooltip with timestamp, cumulative time, share, queries, and memory. Slow checkpoints get a tiered red signal (light / medium / heavy) based on how many multiples of the slow threshold they exceeded. Hovering a row cross-highlights its segment in the overview bar (and vice versa).
- Long-duration display is now consistent at unit boundaries — `999.6ms` renders as `1s`, `59.996s` as `1m 0s`, instead of the impossible `1000ms` / `60s` produced by the previous formatter.

### Internal

- Extracted HTML rendering for a row into `StopwatchCheckpointHtmlRenderer` and the icon set into `StopwatchIcons`. Both classes plus `StopwatchCheckpointCollection::render()` / `renderSegments()` are marked `@internal` — their signatures and output may change between minor releases without a deprecation cycle.
- Tests split across `StopwatchTest`, `StopwatchHtmlRenderTest`, `StopwatchTrackingTest`, and `StopwatchNotificationTest` to keep the per-file scope manageable.

### Upgrade notes

The existing `Stopwatch::render()` API is unchanged — you'll just see the new card. If you've been overriding the rendered HTML's CSS, see `UPGRADING.md` for the new class names and the CSS variables you can override.

## [0.4.2](https://github.com/SanderMuller/Stopwatch/compare/v0.4.1...v0.4.2) - 2026-03-24

### Added

- Laravel Debugbar integration: checkpoint timings appear as a timeline tab with a duration badge when `barryvdh/laravel-debugbar` is installed.
- Slow-stopwatch notifications via `notifyIfSlowerThan()` and a pluggable `StopwatchNotificationChannel` interface (`LogChannel`, `MailChannel`, or custom).
- `MailChannel` for emailing the stopwatch's HTML report when the configured threshold is exceeded.

### Changed

- Pint and CI workflow updates.

## [0.4.1](https://github.com/SanderMuller/Stopwatch/compare/v0.4.0...v0.4.1) - 2026-03-22

### Added

- Memory tracking via `withMemoryTracking()` — captures usage, delta, and peak per checkpoint.
- Query tracking via `withQueryTracking()` — captures count and total time per checkpoint via Laravel's query event.
- `StopwatchMiddleware` that adds a `Server-Timing` header to responses, with an `autoStart()` variant.
- Laravel 13 support.

## [0.4.0](https://github.com/SanderMuller/Stopwatch/compare/v0.3.5...v0.4.0) - 2026-03-22

### Changed

- **BREAKING**: `finish()` no longer adds a synthetic "Ended StopWatch" checkpoint. Only checkpoints you explicitly create are included. The tail time is still surfaced in the HTML footer and via `timeSinceLastCheckpointReadable()`.
- **BREAKING**: `lastCheckpointFormatted()` now includes metadata when present, e.g. `[3ms / 10ms] Label (queries=5)`.
- **BREAKING**: `Stopwatch` constructor is now private — use `Stopwatch::new()` or the `stopwatch()` helper.

## [0.3.5](https://github.com/SanderMuller/Stopwatch/compare/v0.3.4...v0.3.5) - 2025-09-21

### Fixed

- `timeSinceStopwatchStart` calculation for the first checkpoint.

## [0.3.4](https://github.com/SanderMuller/Stopwatch/compare/v0.3.3...v0.3.4) - 2025-09-21

### Changed

- Test improvements.

## [0.3.3](https://github.com/SanderMuller/Stopwatch/compare/v0.3.2...v0.3.3) - 2025-09-19

### Fixed

- `stopwatch()->log()` not honoring the requested log level.

## [0.3.2](https://github.com/SanderMuller/Stopwatch/compare/v0.3.1...v0.3.2) - 2025-09-19

### Added

- Additional tests covering the new helper API.

## [0.3.1](https://github.com/SanderMuller/Stopwatch/compare/v0.3.0...v0.3.1) - 2025-09-15

### Fixed

- Minor fixes following the 0.3.0 release.

## [0.3.0](https://github.com/SanderMuller/Stopwatch/compare/v0.2.0...v0.3.0) - 2025-09-15

### Added

- `stopwatch()` global helper (Laravel) for ergonomic access to a scoped stopwatch.
- `stopwatch()->log()` shortcut for emitting a single checkpoint to the log.
- Configurable output modes (`Silent`, `Log`, `Stderr`, `Dump`) via `outputTo()`.

### Changed

- **BREAKING**: `Stopwatch::start()` is replaced by `stopwatch()->start()` (or `Stopwatch::new()->start()` if you prefer not to use the helper).
- PHP 8.3 minimum.

## [0.2.0](https://github.com/SanderMuller/Stopwatch/compare/v0.1.8...v0.2.0) - 2025-02-24

### Added

- Laravel 12 support.

## [0.1.8](https://github.com/SanderMuller/Stopwatch/compare/v0.1.7...v0.1.8) - 2025-01-05

### Changed

- Enabled all Rector rules and applied resulting refactors.

## [0.1.7](https://github.com/SanderMuller/Stopwatch/compare/v0.1.6...v0.1.7) - 2024-10-13

### Fixed

- PHPStan array type annotations.

## [0.1.6](https://github.com/SanderMuller/Stopwatch/compare/v0.1.5...v0.1.6) - 2024-10-13

### Changed

- Dependency bumps.

## [0.1.5](https://github.com/SanderMuller/Stopwatch/compare/v0.1.4...v0.1.5) - 2024-10-07

### Changed

- Dependency bumps (`tomasvotruba/type-coverage` widened range).

## [0.1.4](https://github.com/SanderMuller/Stopwatch/compare/v0.1.3...v0.1.4) - 2024-08-24

### Fixed

- Carbon 3 compatibility regression.

## [0.1.3](https://github.com/SanderMuller/Stopwatch/compare/v0.1.2...v0.1.3) - 2024-07-30

### Added

- Carbon 3 support.

### Fixed

- PHP 8.2 compatibility regression.

## [0.1.2](https://github.com/SanderMuller/Stopwatch/compare/v0.1.1...v0.1.2) - 2024-06-21

### Changed

- Accept a wider range of metadata values (any scalar or `Stringable`).

## [0.1.1](https://github.com/SanderMuller/Stopwatch/compare/v0.1.0...v0.1.1) - 2024-06-21

### Fixed

- Metadata array key docblock type.

## [0.1.0](https://github.com/SanderMuller/Stopwatch/compare/v0.0.4...v0.1.0) - 2024-06-21

### Added

- PHP 8.2 support.

## [0.0.4](https://github.com/SanderMuller/Stopwatch/compare/v0.0.3...v0.0.4) - 2024-06-20

### Changed

- README updates.

## [0.0.3](https://github.com/SanderMuller/Stopwatch/compare/v0.0.2...v0.0.3) - 2024-06-20

### Added

- `symfony/var-dumper` dependency.

### Fixed

- Laravel 10 compatibility.

## [0.0.2](https://github.com/SanderMuller/Stopwatch/compare/v0.0.1...v0.0.2) - 2024-06-20

### Fixed

- `composer.json` metadata.

## [0.0.1](https://github.com/SanderMuller/Stopwatch/releases/tag/v0.0.1) - 2024-06-20

### Added

- Initial release: `Stopwatch` with checkpoints, total/per-checkpoint timing, HTML render, and serialization (`toArray`, `toJson`).
