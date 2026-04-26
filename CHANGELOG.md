# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> This file is auto-populated by CI on release. To document a change for an
upcoming release, add it to `RELEASE_NOTES_<version>.md` at the repo root —
the release workflow promotes it into this file as part of the tag flow.

## [Unreleased](https://github.com/SanderMuller/Stopwatch/compare/v0.5.0...HEAD)

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

## [v0.5.0](https://github.com/SanderMuller/Stopwatch/compare/v0.4.2...v0.5.0) - 2026-04-26

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

**Full Changelog**: https://github.com/SanderMuller/Stopwatch/compare/v0.4.2...v0.5.0
