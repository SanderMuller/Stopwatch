# Request Profiler Injection

## Overview

Inject a Debugbar-style profiler toolbar into rendered HTML responses so developers see per-request timing, memory delta, query count, and outbound HTTP count without opening logs or DevTools. Three opt-in tiers — global, per-route middleware alias, and a `#[ProfileViaStopwatch]` controller attribute — all flow through one injector that reuses the existing `StopwatchMiddleware` autostart and `StopwatchHtmlRenderer` output. Production is protected by a hard `only_local` guard plus content-type/streaming/ajax filters.

---

## 1. Prior art & reuse

Already in tree:

- `src/StopwatchMiddleware.php` — autostart + finish + `Server-Timing` header.
- `src/StopwatchHtmlRenderer.php` (578 LOC) — `@internal`, emits inline `<script>` (uses `window.__swInit` + `localStorage` for theme toggle and hover cross-highlighting) plus inline `<style>`. **Cannot be reused as-is for a "JS-free" toolbar.** Two paths:
  1. Use it inside the expanded panel and accept that strict-CSP users (`script-src` without `'unsafe-inline'`) cannot use injection. Document loudly.
  2. Add a new `StopwatchToolbarRenderer` that produces JS-free markup (CSS-only `<details>` toggle, no theme persistence) and keep `StopwatchHtmlRenderer` for the `@stopwatch` Blade directive path.

  Picking **(2)** for v1 — the existing renderer's `@internal` annotation means we should not freeze it as an injection contract, and a JS-free toolbar is small (~100 LOC).
- `Stopwatch` data source — `Stopwatch::finalRunTotals()` (line 826) returns the aggregates we need (`duration_ms`, `memory_delta_bytes`, query/http totals). We consume that array; we do not depend on individual public getters.
- `ServiceProvider::packageBooted()` — already registers a `@stopwatch` Blade directive; it's where we register the new middleware alias.

Out of scope: Livewire/Inertia partial updates, streamed responses, non-HTML responses. Octane/Swoole are not just "out of scope" — they are **hard-disabled** at runtime (see §3, guard 1.5) because the `Stopwatch` singleton is per-process and would mix data across requests.

---

## 2. Config

Add a new top-level `inject` block to `config/stopwatch.php`:

```php
'inject' => [
    'mode' => env('STOPWATCH_INJECT', 'off'),         // off | all | route | attribute
    'allowed_environments' => env('STOPWATCH_INJECT_ENVIRONMENTS', 'local'), // CSV
    'position' => env('STOPWATCH_INJECT_POSITION', 'bottom-right'), // bottom-right | bottom-left | top-right | top-left
    'slow_request_threshold_ms' => (int) env('STOPWATCH_INJECT_SLOW_REQUEST_MS', 500),
],
```

Mode semantics:

| `mode`      | Behavior                                                                                   |
|-------------|--------------------------------------------------------------------------------------------|
| `off`       | Default. Injector never runs. Existing `Server-Timing` header path unchanged.              |
| `all`       | Inject on every eligible HTML response (Debugbar parity).                                  |
| `route`     | Inject only when the route's middleware list contains `stopwatch.inject` alias.            |
| `attribute` | Inject only when the resolved controller class or method carries `#[ProfileViaStopwatch]`. |

### Environment guard

Default-deny by environment. `allowed_environments` is a CSV (or already-parsed array) of environment names whose responses may be injected. Default: `local` only. The injector calls `app()->environment(...$allowed)`; any environment not in the list — including `staging`, `dev`, `testing`, `preview`, `production`, and any custom name — short-circuits before mode dispatch.

**Why default-deny rather than `! production`:** the toolbar's expanded panel exposes raw SQL with bound values via the existing query renderer (`src/StopwatchQueryRenderer.php`). Public-internet staging and shared dev environments are common; a `not-production` allow-rule would leak SQL + bindings to anyone who can reach those URLs. Operators must explicitly opt each environment in (e.g. `STOPWATCH_INJECT_ENVIRONMENTS=local,docker`). This is the package's published threat model: the toolbar is for environments where the developer trusts every viewer. Document the data-exposure risk in the README.

`ServiceProvider::configureStopwatch()` reads nothing new — config is consumed inside the middleware (mode is a per-request decision, not a singleton wiring step).

---

## 3. Middleware topology

Split injection from autostart cleanly:

- `StopwatchMiddleware:autostart` — unchanged; the package's existing autostart middleware. It is what calls `Stopwatch::start()` (when `:autostart` flag set) and `Stopwatch::finish()` *after* `$next` returns. **Required for any inject mode.** A passive (non-autostart) `StopwatchMiddleware` registration is not sufficient — guard 9 (§3) requires both `started()` and `ended()` before injection, and only the autostart variant guarantees both for arbitrary HTML routes.
- **New** `StopwatchInjectMiddleware` (separate class) — only concerns itself with toolbar injection. Does *not* call `Stopwatch::start()`/`finish()`; relies on the autostart middleware. Idempotent: if it runs twice on the same response, the second pass is a no-op via the §5 marker.

### Ordering is load-bearing

`StopwatchMiddleware::autoStart()` calls `Stopwatch::finish()` only **after** its own `$next` returns. `StopwatchInjectMiddleware` reads aggregates *post*-`$next`. For the injector to see a finished stopwatch, **inject must wrap autostart** — i.e. inject must be the outer middleware so its post-`$next` block runs *after* autostart's post-`$next` block.

In Laravel's pipeline, middleware listed earlier is outer. The required registration order is therefore:

```php
$middleware->append(StopwatchInjectMiddleware::class);          // outer — runs after()
$middleware->append(StopwatchMiddleware::autoStart());          // inner — finishes the stopwatch
```

If reversed, the injector runs before `finish()` and guard 9 short-circuits every request — silent failure. The README must document this ordering explicitly. `route` mode (alias on a specific route) inherits the same constraint: the route's effective stack must place the inject alias outside the autostart middleware (which, for global autostart, it already is — route middleware runs inner to global middleware).

### Required topology per mode

| Mode        | Required topology                                                                                                                         |
|-------------|-------------------------------------------------------------------------------------------------------------------------------------------|
| `off`       | Anything. Inject middleware no-ops.                                                                                                       |
| `all`       | `StopwatchInjectMiddleware` registered globally **outer to** `StopwatchMiddleware::autoStart()` (also globally registered).               |
| `route`     | `StopwatchMiddleware::autoStart()` global; `stopwatch.inject` alias on opted-in routes only. (Route alias runs inner to global by default — but the inject alias still wraps the controller, and autostart's `finish()` runs at the global layer outside the alias, so guard 9 fails. **Reordering required: see note below.**) |
| `attribute` | Same as `all` — `StopwatchInjectMiddleware` global, outer to autostart; filters by attribute presence at request time.                    |

**`route` mode caveat:** because Laravel runs route-level middleware inner to global middleware, a global autostart will `finish()` *after* the route-alias inject runs. Two ways to fix: (a) register autostart as a route middleware on the same routes (loses Server-Timing on non-injected routes), or (b) register autostart globally **and** have `StopwatchInjectMiddleware` (registered globally as well, outer to autostart) check `mode=route` + the route's middleware list to decide whether to inject. Option (b) is the documented path — `route` mode does **not** require the alias to be the actual injecting middleware; the alias is a *marker* the global inject middleware reads via `$request->route()->gatherMiddleware()`.

`mode` itself is the kill-switch for the inject middleware — even if registered globally, `mode=off` makes it a no-op (so a `.env` flip disables the whole feature without touching route files).

`StopwatchInjectMiddleware::handle()` (post-`$next` only — nothing happens before the controller):

```php
if (! $this->shouldInject($request, $response)) {
    return $response;
}

$this->injector->inject($response);

return $response;
```

`shouldInject()` short-circuits on, in order:

1. `inject.mode === 'off'` → false.
2. **Octane/Swoole detection** — if `app()->bound('octane')` (or `extension_loaded('swoole')` and the app is running under Octane) → false. Hard guard, regardless of mode. Reason: shared singleton across requests means injected toolbar would show data from a previous request.
3. **Environment allow-list** — `! app()->environment(...$allowedEnvironments)` → false. Default allow-list is `['local']`; operators opt others in via `STOPWATCH_INJECT_ENVIRONMENTS`. See §2 for the data-exposure rationale.
4. Response not 2xx → false.
5. `Content-Type` not `text/html` (charset suffix allowed, e.g. `text/html; charset=UTF-8`). XHTML (`application/xhtml+xml`) is **not** included in v1 — document explicitly. → false.
6. `Content-Encoding` set and not `identity` (e.g. `gzip`, `br`, `deflate`) → false.
7. Response is `StreamedResponse` / `BinaryFileResponse` → false.
8. Fragment/partial-request signals → false: `$request->ajax()`, `$request->wantsJson()`, `$request->pjax()`, header `HX-Request: true` (HTMX), header `X-Livewire: true`, header `X-Inertia: true`.
9. Stopwatch must be both started and finished (`$this->stopwatch->started() && $this->stopwatch->ended()`) → otherwise no data to render. Failing this guard is the canonical signal that the autostart middleware is missing or the inject middleware is registered inner-to autostart (see §3 ordering note). Implementers should log a one-shot debug warning the first time guard 9 fails in a non-production environment to surface the misconfiguration.
10. Mode-specific check:
    - `all` → true.
    - `route` → true if the resolved route's gathered middleware contains the `stopwatch.inject` alias (`$request->route()?->gatherMiddleware()`). The alias acts as a marker, not the injecting middleware itself — see §3 `route` mode caveat.
    - `attribute` → resolve controller per §4, return true if class or invoked method carries `#[ProfileViaStopwatch]`.
    - **Combination:** if `mode=attribute` and the route also carries the `stopwatch.inject` alias, inject. (Resolves the §7 closure-route fallback claim — closure routes have no class to attach an attribute to, so the alias is the only opt-in available, and it must work even under `mode=attribute`.) `mode=route` does **not** read attributes; users who want attribute opt-in must use `mode=attribute`.

---

## 4. Attribute

```php
namespace SanderMuller\Stopwatch;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class ProfileViaStopwatch {}
```

Marker only — no parameters in v1. Either class-level *or* method-level presence triggers injection (boolean OR — no precedence to define for a paramless attribute).

Resolution uses Laravel's normalized route API first, falling back to action parsing only when needed. Covers all four route shapes: `'Class@method'` string, `[Class::class, 'method']` tuple, invokable `Class` (via `__invoke`), and closures (returns false).

```php
$route = $request->route();

if ($route === null) {
    return false;
}

$class = $route->getControllerClass(); // null for closure routes; handles tuple + string + invokable
$method = $route->getActionMethod();   // '__invoke' for invokables; method name otherwise

if ($class === null || ! class_exists($class)) {
    return false;
}

$reflection = new \ReflectionClass($class);

if ($reflection->getAttributes(ProfileViaStopwatch::class) !== []) {
    return true;
}

return $reflection->hasMethod($method)
    && $reflection->getMethod($method)->getAttributes(ProfileViaStopwatch::class) !== [];
```

`Route::getControllerClass()` and `Route::getActionMethod()` are Laravel's own normalizers (they handle `Class@method`, tuple `[Class::class, 'method']`, and invokables uniformly). Closure routes return `null` from `getControllerClass()` — we exit early. No manual `@` parsing.

---

## 5. Injector

New class `src/StopwatchInjector.php`. Single public method `inject(Response $response): void` that:

1. Reads body via `$response->getContent()`.
2. **Idempotency guard** — if body contains `<!--stopwatch-toolbar-->` marker, return without modification.
3. Locates closing `</body>` using **byte-oriented `strripos`** on the raw body (last occurrence, case-insensitive). If absent → no-op (user might be returning a fragment with `Content-Type: text/html`; never synthesize a `<body>`).
4. Builds toolbar HTML (see §6) including the marker as the first character.
5. `substr_replace($body, $toolbar, $pos, 0)` — both `strripos` and `substr_replace` operate on byte offsets, so they compose safely on multibyte content. **Do not use `mb_strripos`** — it returns character offsets and would corrupt multibyte bodies when paired with `substr_replace`.
6. Writes the new body back via `$response->setContent($newBody)`.
7. **Strip body-derived headers** that are now stale: remove `Content-Length` (Symfony will recompute on send), `ETag`, and `Last-Modified` if they were set upstream. Letting them leak through after mutation is a cache-poisoning hazard.

No regex over full body — Debugbar's injector took years of bug reports to harden. We do one `strripos` + one `substr_replace`.

Charset note: the toolbar uses emoji glyphs (`⏱ 🧠 🗄 🌐`) which are valid UTF-8. We assume `Content-Type: text/html; charset=UTF-8` (Laravel default) and document that non-UTF-8 responses are not supported — they will be skipped if `charset` is present and not UTF-8.

---

## 6. Toolbar markup

New class `src/StopwatchToolbarRenderer.php` — produces the entire injected blob. **Does not reuse `StopwatchHtmlRenderer`** (that renderer ships inline `<script>`, `window.__swInit`, and `localStorage` — incompatible with strict CSP, and its `@internal` annotation means we should not freeze its output as a contract).

Two parts:

**Collapsed bar** (always visible):

```
[ ⏱ 487ms · 🧠 +2.3MB · 🗄 32q (245ms) · 🌐 4h (120ms) ]
```

Data sources (public API only — no new getters, no reaching into `@internal` renderers):

- **Aggregates** (collapsed bar): `Stopwatch::finalRunTotals()` — same array persisted to run-log frontmatter (`duration_ms`, `memory_delta_bytes`, query/http totals).
- **Per-checkpoint rows** (expanded panel): `Stopwatch::checkpoints()` — public snapshot returning the `StopwatchCheckpointCollection`. The toolbar renderer iterates this collection directly to produce the JS-free table. Do **not** reuse `StopwatchCheckpointHtmlRenderer` / `StopwatchExpansionRenderer` — both are `@internal` and emit JS.

Pill color rules:

- Duration pill red when total `duration_ms` exceeds `inject.slow_request_threshold_ms` (new config, default `500`). Distinct from the per-checkpoint `slow_threshold` (which would always trigger on any real request).

**Expanded panel** — toggled via `<details>` (CSS-only, zero JS):

```html
<!--stopwatch-toolbar-->
<details id="stopwatch-toolbar" class="sw-pos-bottom-right">
  <summary>{collapsed bar markup}</summary>
  <div class="sw-panel">
    {checkpoint table — JS-free render: position, label, Δ, cumulative, share %, queries, http, memory delta}
  </div>
</details>
```

The expanded panel is a static HTML table — same data the existing `StopwatchHtmlRenderer` shows, but without the hover cross-highlighting, theme toggle, or tooltip JS. Markup is small (~100 LOC of renderer + template).

Styling: inline `<style>` block scoped under `#stopwatch-toolbar` (no global selectors) so host-page CSS can't bleed in and our styles can't bleed out. Position class driven by `inject.position` config. No external assets, no fonts, no images — emoji glyphs only.

**CSP**: inline `<style>` requires `'unsafe-inline'` in `style-src` *or* a nonce. v1 ships only the inline-style path; document the CSP cost in the README. Future enhancement (deferred to Open Question §3): nonce support via a callback, or a published asset.

---

## 7. Edge cases

- **Toolbar already injected** — covered by `<!--stopwatch-toolbar-->` marker in §5.
- **Stopwatch not started or not finished** — `shouldInject()` guard 9 returns false before reading aggregates.
- **Closure routes under `mode=attribute`** — no controller class to attach the attribute to, so the attribute check returns false. Adding the `stopwatch.inject` alias to the closure route opts it in: per §3 guard 10, `mode=attribute` also injects when the alias marker is present on the route.
- **Multiple `</body>`** — `strripos` picks the last; matches Debugbar.
- **Octane / Swoole** — hard-disabled at runtime via §3 guard 2. Documented in README.
- **HTMX / Livewire / Inertia partial swaps** — guarded via §3 guard 8 (header sniff).
- **Body-derived headers** — `Content-Length`, `ETag`, `Last-Modified` stripped post-injection per §5.7 to avoid cache poisoning.
- **Non-UTF-8 charset** — skipped at §3 guard 5 if `charset` is present and not UTF-8.
- **XHTML (`application/xhtml+xml`)** — not supported in v1; explicitly documented.

---

## Implementation

### Phase 1: Config + middleware plumbing (Priority: HIGH)

- [x] Add `inject` block to `config/stopwatch.php` — keys `mode`, `allowed_environments` (CSV → array), `position`, `slow_request_threshold_ms` w/ env vars.
- [x] New `src/StopwatchInjectMiddleware.php` — separate class from `StopwatchMiddleware`. Constructor injects `Stopwatch` and `StopwatchInjector`. Post-`$next` only.
- [x] Register `stopwatch.inject` route alias in `ServiceProvider::packageBooted()` pointing at `StopwatchInjectMiddleware`. (Note: in `route` mode the alias is a marker the global inject middleware reads; the alias-resolved instance still no-ops via the `<!--stopwatch-toolbar-->` idempotency marker if it runs. See §3 caveat.)
- [x] Tests — alias resolves; middleware no-ops when `mode=off`; config defaults are `off` / `['local']` / `bottom-right` / `500`; CSV env parsing splits and trims correctly.

### Phase 2: Eligibility guards (Priority: HIGH)

- [x] Implement `shouldInject()` on `StopwatchInjectMiddleware` — guards 1–10 per §3, each rule independently testable.
- [x] Octane/Swoole detection helper — `app()->bound('octane')` or Octane facade presence.
- [x] Tests — each guard rejects in isolation: mode=off; Octane bound; environment not in allow-list (default-deny: staging, dev, testing, production each rejected with default config; allow-list `local,docker` admits both); non-2xx; non-html / xhtml / non-UTF-8 charset; gzip `Content-Encoding`; `StreamedResponse`; `BinaryFileResponse`; `ajax()`, `wantsJson()`, `pjax()`, `HX-Request`, `X-Livewire`, `X-Inertia`; stopwatch not started; stopwatch not finished (with assertion that the misconfiguration debug log fires once); inject middleware registered inner-to autostart (regression test for ordering bug). Happy path: HTML 200, local env, `mode=all`, autostart-then-inject in correct order → true.

### Phase 3: Injector + toolbar renderer (Priority: HIGH)

- [x] `src/StopwatchInjector.php` — `inject(Response): void` using byte-oriented `strripos` + `substr_replace`; idempotent via `<!--stopwatch-toolbar-->` marker; strips `Content-Length`, `ETag`, `Last-Modified` after mutation.
- [x] `src/StopwatchToolbarRenderer.php` — JS-free `<details>` markup, scoped inline `<style>`, position class from config, pill colored by `slow_request_threshold_ms`. Reads aggregates from `Stopwatch::finalRunTotals()` and per-checkpoint rows from `Stopwatch::checkpoints()` (both public). No reaching into `@internal` renderers.
- [x] Tests — injector: byte-exact body before `</body>` preserved; runs twice → single toolbar; multibyte body (CJK, emoji) → no corruption; missing `</body>` → no-op; `Content-Length`/`ETag`/`Last-Modified` removed when set. Renderer: collapsed bar shows duration/memory/queries/http; pill colored when over threshold; expanded panel renders all checkpoints; output contains the marker.

### Phase 4: Attribute mode (Priority: HIGH)

- [x] `src/ProfileViaStopwatch.php` — `#[Attribute(TARGET_CLASS | TARGET_METHOD)]`, no params, `final readonly class`.
- [x] Attribute resolver inside `StopwatchInjectMiddleware` — uses `Route::getControllerClass()` + `Route::getActionMethod()`, no manual `@` parsing.
- [x] Tests — attribute on class triggers; on method triggers; both present triggers; absent on both → false; closure route under `mode=attribute` → false unless route also has `stopwatch.inject` alias (per §3 guard 10 combination); invokable controller (single `__invoke`) honored; tuple-style action `[Class::class, 'method']` honored.

### Phase 5: Docs + release notes (Priority: MEDIUM)

- [x] README — new "Inject" section after the `Server-Timing` docs; show all three tiers, **middleware-ordering rule** (inject outer / autostart inner — failure mode: silent no-op), **environment allow-list** with data-exposure warning (raw SQL + bindings exposed in the panel), CSP caveat, Octane caveat, position options.
- [x] `RELEASE_NOTES_<next>.md` — describe the three modes, the attribute, the `STOPWATCH_INJECT*` env vars (including the `STOPWATCH_INJECT_ENVIRONMENTS` allow-list and the security rationale). Do not touch `CHANGELOG.md` (CI promotes it).
- [x] Tests — none (docs).

---

## Open Questions

1. **Position values — config string vs. attribute parameter?** Currently config-only. If users want `#[ProfileViaStopwatch(position: 'top-left')]` per controller, attribute needs a constructor — not hard, but locks the API.
2. **CSP-strict opt-out — emit a `<link rel="stylesheet">` to a published asset instead of inline `<style>`?** Adds an asset publish step and one more HTTP request per page. Probably defer until someone asks.
3. **`route` mode marker-based design — keep, or split into two middleware?** §3 caveat resolves the ordering bug by making the route alias a *marker* the global inject middleware reads, rather than the actual injecting middleware. Functionally correct but conceptually surprising (the alias does nothing on its own). Alternative: ship two inject middleware classes — one global (for `all` / `attribute`) and one route-scoped (for `route`) — with the route-scoped one collaborating via a request-attribute flag with a global "finish-then-inject" middleware. Defer until someone trips on the marker model.

---

## Resolved Questions

1. **Attribute name: `#[ProfileViaStopwatch]` vs `#[Stopwatch]` vs `#[ProfileRequest]`?** **Decision:** `#[ProfileViaStopwatch]`. **Rationale:** `#[Stopwatch]` collides with the `Stopwatch` class in `use` statements, forcing alias gymnastics. `#[ProfileRequest]` loses brand. `#[ProfileViaStopwatch]` is collision-free, self-documenting at the call site, and signals package ownership.
2. **Reuse `StopwatchHtmlRenderer` for the expanded panel, or new renderer?** **Decision:** New `StopwatchToolbarRenderer`. **Rationale:** Codex review surfaced that the existing renderer is `@internal`, ships inline `<script>` (`window.__swInit`, `localStorage`), and is incompatible with strict CSP. Treating its output as a stable injection contract would block strict-CSP users entirely and freeze an internal API. A JS-free toolbar renderer is small.
3. **One middleware with `inject` flag vs two middleware classes?** **Decision:** Two classes — `StopwatchMiddleware` (autostart, unchanged) and new `StopwatchInjectMiddleware`. **Rationale:** Codex flagged that combining concerns made some `mode` + registration combos silently no-op and others double-fire. Splitting makes each mode's required topology explicit.
4. **Octane/Swoole — document or hard-disable?** **Decision:** Hard-disable at runtime. **Rationale:** `Stopwatch` is a per-process singleton; under Octane the toolbar would show *previous request's* data. That is a correctness bug, not a "limitation."
5. **Middleware ordering — silent no-op vs explicit error?** **Decision:** Silent no-op via guard 9, with a one-shot debug log when guard 9 fails in non-production environments. **Rationale:** Codex review surfaced that `inject` middleware registered inner-to autostart sees `started() && !ended()` and skips every request. Hard-erroring would brick apps where the user's middleware order is otherwise valid; logging once on first miss gives a debugging signal without spamming.
6. **`only_local` semantics — `! production` vs explicit allow-list?** **Decision:** Default-deny allow-list (`STOPWATCH_INJECT_ENVIRONMENTS`, default `local`). **Rationale:** Codex review flagged that the toolbar's expanded panel exposes raw SQL + bindings via the existing query renderer, and `! production` would auto-enable injection on public staging/dev/preview environments. Default-deny by environment name forces operators to opt each environment in, with a documented threat model that the toolbar is for trusted-viewer environments only.
7. **Per-checkpoint data source — `@internal` renderer reuse vs public collection?** **Decision:** Toolbar renderer iterates `Stopwatch::checkpoints()` (public snapshot) and emits its own row markup. **Rationale:** Codex review flagged that the spec named only `finalRunTotals()` while requiring per-checkpoint rows in the expanded panel — implementers would otherwise reach into `StopwatchCheckpointHtmlRenderer` (which is `@internal` and emits JS, defeating the JS-free toolbar goal) or the mutable collection. Naming the public method as the contract closes the gap.

## Findings

<!-- Notes added during implementation. Do not remove this section. -->

- **Route alias resolution.** The middleware class itself appears in `$route->gatherMiddleware()` when registered explicitly, so the alias-presence check matches the literal alias string only (`stopwatch.inject`), not the FQCN. Otherwise every globally-registered route would falsely "have the alias".
- **One-shot ordering hint.** Implemented as a static flag with `resetOrderingHintForTesting()` so the warning fires once per process and tests can rearm it. Skipped in production to avoid leaking middleware-topology hints to operator log streams.
- **Toolbar markup.** Emoji glyphs use HTML entities (`&#9201;` etc.) inside the rendered string to avoid relying on the source file's literal UTF-8 round-tripping through CI / log pipelines. The byte-level injector preserves multibyte response bodies regardless.
- **Phpstan ignores.** `StopwatchInjectMiddleware::shouldInject()` carries a `complexity.functionLike` ignore; the guard chain is intentionally flat (10 short-circuit clauses) to match the spec's numbered list.
- **Header capture.** `Content-Encoding` is normalised via `strtolower` so `Identity` / `IDENTITY` are still treated as no-encoding. Charset comparison is also case-folded.
- **Release notes.** Written to `RELEASE_NOTES_0.9.0.md` (next minor — `0.8.0` is the most recent tag in `CHANGELOG.md`). Did not touch `CHANGELOG.md`.
- **Alias is a separate marker class (`StopwatchInjectAlias`).** Initial implementation aliased `stopwatch.inject` directly to `StopwatchInjectMiddleware`, which meant a route-level alias caused the inject middleware to run twice — once at the route layer (inner to autostart) and once globally. The inner instance saw `started() && !ended()` and falsely tripped the ordering warning. Fix: a no-op marker middleware (`StopwatchInjectAlias`) sits behind the alias; the global inject middleware reads its presence in `gatherMiddleware()` to decide whether to inject under `mode=route` (and as the closure-route fallback under `mode=attribute`). Regression test: `AttributeModeTest::test_alias_does_not_trigger_ordering_warning_or_double_inject`.
