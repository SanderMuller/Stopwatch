# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> This file is auto-populated by CI on release. To document a change for an
> upcoming release, add it to `RELEASE_NOTES_<version>.md` at the repo root —
> the release workflow promotes it into this file as part of the tag flow.

## [Unreleased]

## [0.4.2] - 2026-03-24

### Added
- Laravel Debugbar integration: checkpoint timings appear as a timeline tab with a duration badge when `barryvdh/laravel-debugbar` is installed.
- Slow-stopwatch notifications via `notifyIfSlowerThan()` and a pluggable `StopwatchNotificationChannel` interface (`LogChannel`, `MailChannel`, or custom).
- `MailChannel` for emailing the stopwatch's HTML report when the configured threshold is exceeded.

### Changed
- Pint and CI workflow updates.

## [0.4.1] - 2026-03-22

### Added
- Memory tracking via `withMemoryTracking()` — captures usage, delta, and peak per checkpoint.
- Query tracking via `withQueryTracking()` — captures count and total time per checkpoint via Laravel's query event.
- `StopwatchMiddleware` that adds a `Server-Timing` header to responses, with an `autoStart()` variant.
- Laravel 13 support.

## [0.4.0] - 2026-03-22

### Changed
- **BREAKING**: `finish()` no longer adds a synthetic "Ended StopWatch" checkpoint. Only checkpoints you explicitly create are included. The tail time is still surfaced in the HTML footer and via `timeSinceLastCheckpointReadable()`.
- **BREAKING**: `lastCheckpointFormatted()` now includes metadata when present, e.g. `[3ms / 10ms] Label (queries=5)`.
- **BREAKING**: `Stopwatch` constructor is now private — use `Stopwatch::new()` or the `stopwatch()` helper.

## [0.3.5] - 2025-09-21

### Fixed
- `timeSinceStopwatchStart` calculation for the first checkpoint.

## [0.3.4] - 2025-09-21

### Changed
- Test improvements.

## [0.3.3] - 2025-09-19

### Fixed
- `stopwatch()->log()` not honoring the requested log level.

## [0.3.2] - 2025-09-19

### Added
- Additional tests covering the new helper API.

## [0.3.1] - 2025-09-15

### Fixed
- Minor fixes following the 0.3.0 release.

## [0.3.0] - 2025-09-15

### Added
- `stopwatch()` global helper (Laravel) for ergonomic access to a scoped stopwatch.
- `stopwatch()->log()` shortcut for emitting a single checkpoint to the log.
- Configurable output modes (`Silent`, `Log`, `Stderr`, `Dump`) via `outputTo()`.

### Changed
- **BREAKING**: `Stopwatch::start()` is replaced by `stopwatch()->start()` (or `Stopwatch::new()->start()` if you prefer not to use the helper).
- PHP 8.3 minimum.

## [0.2.0] - 2025-02-24

### Added
- Laravel 12 support.

## [0.1.8] - 2025-01-05

### Changed
- Enabled all Rector rules and applied resulting refactors.

## [0.1.7] - 2024-10-13

### Fixed
- PHPStan array type annotations.

## [0.1.6] - 2024-10-13

### Changed
- Dependency bumps.

## [0.1.5] - 2024-10-07

### Changed
- Dependency bumps (`tomasvotruba/type-coverage` widened range).

## [0.1.4] - 2024-08-24

### Fixed
- Carbon 3 compatibility regression.

## [0.1.3] - 2024-07-30

### Added
- Carbon 3 support.

### Fixed
- PHP 8.2 compatibility regression.

## [0.1.2] - 2024-06-21

### Changed
- Accept a wider range of metadata values (any scalar or `Stringable`).

## [0.1.1] - 2024-06-21

### Fixed
- Metadata array key docblock type.

## [0.1.0] - 2024-06-21

### Added
- PHP 8.2 support.

## [0.0.4] - 2024-06-20

### Changed
- README updates.

## [0.0.3] - 2024-06-20

### Added
- `symfony/var-dumper` dependency.

### Fixed
- Laravel 10 compatibility.

## [0.0.2] - 2024-06-20

### Fixed
- `composer.json` metadata.

## [0.0.1] - 2024-06-20

### Added
- Initial release: `Stopwatch` with checkpoints, total/per-checkpoint timing, HTML render, and serialization (`toArray`, `toJson`).

[Unreleased]: https://github.com/SanderMuller/Stopwatch/compare/v0.4.2...HEAD
[0.4.2]: https://github.com/SanderMuller/Stopwatch/compare/v0.4.1...v0.4.2
[0.4.1]: https://github.com/SanderMuller/Stopwatch/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/SanderMuller/Stopwatch/compare/v0.3.5...v0.4.0
[0.3.5]: https://github.com/SanderMuller/Stopwatch/compare/v0.3.4...v0.3.5
[0.3.4]: https://github.com/SanderMuller/Stopwatch/compare/v0.3.3...v0.3.4
[0.3.3]: https://github.com/SanderMuller/Stopwatch/compare/v0.3.2...v0.3.3
[0.3.2]: https://github.com/SanderMuller/Stopwatch/compare/v0.3.1...v0.3.2
[0.3.1]: https://github.com/SanderMuller/Stopwatch/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/SanderMuller/Stopwatch/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/SanderMuller/Stopwatch/compare/v0.1.8...v0.2.0
[0.1.8]: https://github.com/SanderMuller/Stopwatch/compare/v0.1.7...v0.1.8
[0.1.7]: https://github.com/SanderMuller/Stopwatch/compare/v0.1.6...v0.1.7
[0.1.6]: https://github.com/SanderMuller/Stopwatch/compare/v0.1.5...v0.1.6
[0.1.5]: https://github.com/SanderMuller/Stopwatch/compare/v0.1.4...v0.1.5
[0.1.4]: https://github.com/SanderMuller/Stopwatch/compare/v0.1.3...v0.1.4
[0.1.3]: https://github.com/SanderMuller/Stopwatch/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/SanderMuller/Stopwatch/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/SanderMuller/Stopwatch/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/SanderMuller/Stopwatch/compare/v0.0.4...v0.1.0
[0.0.4]: https://github.com/SanderMuller/Stopwatch/compare/v0.0.3...v0.0.4
[0.0.3]: https://github.com/SanderMuller/Stopwatch/compare/v0.0.2...v0.0.3
[0.0.2]: https://github.com/SanderMuller/Stopwatch/compare/v0.0.1...v0.0.2
[0.0.1]: https://github.com/SanderMuller/Stopwatch/releases/tag/v0.0.1
