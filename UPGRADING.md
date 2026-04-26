# Upgrading from Stopwatch 0.4.x to 0.5.x

## HTML render redesigned

`stopwatch()->render()` now emits a substantially redesigned card. Highlights:

- Auto light/dark theming via `prefers-color-scheme`, with a manual toggle button (sun/moon) in the header that persists the user's choice in `localStorage` under the `sw-theme` key. Pages that disallow JavaScript fall back to system preference; the toggle button is hidden in that case.
- Slow checkpoints get a tiered red signal (light / medium / heavy) based on how many multiples of the slow threshold they exceeded.
- Compact duration formatter (`Stopwatch::formatDuration`) — `3.4ms`, `143ms`, `1.25s`, `1m 5s`.
- Hovering a row cross-highlights its segment in the overview bar (and vice versa).
- Empty state when no checkpoints have been recorded.
- Cumulative totals (queries, query time, memory delta) shown in the footer when tracking is enabled.

### Custom CSS overrides

The card root is `.sw-stopwatch`. All themable surfaces are CSS variables (e.g. `--sw-bg`, `--sw-text`, `--sw-border`, `--sw-hover-bg`, `--sw-tip-bg`). To re-skin without forking the renderer, override these variables on `.sw-stopwatch` (or its `[data-theme="dark"]` variant) in your application's stylesheet.

### Internal classes

`StopwatchCheckpointHtmlRenderer`, `StopwatchIcons`, and the rendering helpers on `StopwatchCheckpointCollection` (`render`, `renderSegments`) are marked `@internal`. Their signatures and output may change between minor releases without notice. Consumers should keep using `Stopwatch::render()` / `toHtml()`.

---

# Upgrading from Stopwatch 0.3.x to 0.4.x

## Breaking changes

### `finish()` no longer adds a synthetic checkpoint

Previously, calling `finish()` (or any output method like `toArray()`, `toHtml()`) would automatically add an "Ended StopWatch" checkpoint. This synthetic checkpoint has been removed. Only checkpoints you explicitly create are included.

The time between the last checkpoint and the end of the stopwatch is still visible in the HTML output (shown in the footer) and available via the new `timeSinceLastCheckpointReadable()` method.

### `lastCheckpointFormatted()` now includes metadata

The output of `lastCheckpointFormatted()` now includes metadata when present. For example, a checkpoint with metadata `['queries' => 5]` will now return `[3ms / 10ms] Label (queries=5)` instead of `[3ms / 10ms] Label`. If you parse this string, you may need to update your logic.

### Constructor is now private

Use `Stopwatch::new()` or the `stopwatch()` helper to create instances. Direct `new Stopwatch()` calls will need to be updated.

---

# Upgrading from Stopwatch 0.2.x to 0.3.x

## PHP version requirements

Stopwatch now uses PHP 8.3 or newer to run.

## Breaking changes

`Stopwatch::start()` is replaced by `stopwatch()->start()`, or if you prefer the non helper method, you can use `Stopwatch::new()->start()`.
