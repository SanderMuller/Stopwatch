# Profiler toolbar

A Debugbar-style toolbar injected into eligible HTML responses: per-request duration, memory delta, query and HTTP counts, and a JavaScript-free panel of per-checkpoint deltas.

Each pill carries a tooltip that names the metric it shows. Point at a pill, or tab to it, to read the full text.

## Setup

Set one env var:

```dotenv
STOPWATCH_INJECT=all                      # off | all | route | attribute
STOPWATCH_INJECT_ENVIRONMENTS=local       # CSV, default-deny by environment name
STOPWATCH_INJECT_POSITION=bottom-right    # bottom-right | bottom-left | top-right | top-left
STOPWATCH_INJECT_SLOW_REQUEST_MS=500      # duration pill turns red at or above this
STOPWATCH_INJECT_TRACK=true               # track queries, memory and HTTP while the toolbar is on
```

Clear the config cache afterwards if the app caches it.

The service provider prepends the two middleware for you, `StopwatchInjectMiddleware` outer and `StopwatchMiddleware::autoStart()` inner, once the mode is not `off`, `APP_ENV` is on the allow-list, and the app is not on Octane. A plain install leaves the pipeline untouched.

An active toolbar also turns query, memory and HTTP [tracking](05-tracking.md) on, whatever `STOPWATCH_TRACK_QUERIES`, `STOPWATCH_TRACK_MEMORY` and `STOPWATCH_TRACK_HTTP` say. Without it the query, HTTP and memory columns render as dashes. Set `STOPWATCH_INJECT_TRACK=false` to leave those settings alone.

Autostart finishes the stopwatch on every web request, so a `Server-Timing` header appears and the [run log](09-run-log.md) and [notifications](11-notifications.md) start recording when those are on.

### Manual registration

To place the middleware yourself, turn auto-registration off:

```dotenv
STOPWATCH_INJECT_AUTO_REGISTER=false
```

```php
// bootstrap/app.php
use SanderMuller\Stopwatch\StopwatchInjectMiddleware;
use SanderMuller\Stopwatch\StopwatchMiddleware;

$middleware->append(StopwatchInjectMiddleware::class);   // outer, injects after()
$middleware->append(StopwatchMiddleware::autoStart());   // inner, finishes the stopwatch
```

Order matters. The injector reads its aggregates after `$next` returns, so it must wrap autostart. Reversed, injection silently no-ops, and outside production the middleware logs a one-shot debug line to say so. On Laravel 10 or earlier, use the `web` group in `app/Http/Kernel.php` instead.

## Security: default-deny by environment

`STOPWATCH_INJECT_ENVIRONMENTS` defaults to `local` alone. **The expanded panel exposes raw SQL with bound values.** Staging, dev and preview environments are commonly reachable from the internet, so a `not-production` style allow-rule would publish query bindings to anyone who loads a page. Opt environments in one at a time:

```dotenv
STOPWATCH_INJECT_ENVIRONMENTS=local,docker
```

Treat any environment with the toolbar enabled as trusted-viewer-only.

## Modes

The mode picks which responses the injector touches. It does not narrow autostart, which keeps running on every request.

- **`all`**: every eligible HTML response.
- **`route`**: only routes carrying the `stopwatch.inject` alias, which is always registered:
  ```php
  Route::middleware('stopwatch.inject')->get('/dashboard', /* ... */);
  ```
- **`attribute`**: only when the resolved controller class or method carries `#[ProfileViaStopwatch]`:
  ```php
  use SanderMuller\Stopwatch\ProfileViaStopwatch;

  #[ProfileViaStopwatch]
  final class OrdersController { /* ... */ }
  ```
  A closure route has no class; use the alias for those.

## When it stays out of the way

<details>
<summary>No toolbar? Check these first</summary>

1. `STOPWATCH_INJECT` is not `off`, and `STOPWATCH_ENABLED` is not `false`.
2. `APP_ENV` is in `STOPWATCH_INJECT_ENVIRONMENTS`.
3. The config cache is cleared.
4. With `STOPWATCH_INJECT_AUTO_REGISTER=false`, both middleware are registered, in the order above.
5. The response is a 2xx `text/html` page, not JSON, a redirect, or a Livewire, Inertia or htmx partial.

</details>

<details>
<summary>Eligibility guards</summary>

Injection is skipped for: non-2xx responses; a `Content-Type` that is not `text/html` (or a charset that is not UTF-8); `Content-Encoding` set to anything but `identity`; `StreamedResponse` and `BinaryFileResponse`; ajax, `wantsJson`, pjax, `HX-Request`, `X-Livewire` and `X-Inertia` requests; and any run where the stopwatch was never started or never finished. XHTML (`application/xhtml+xml`) is not supported.

</details>

**Octane and Swoole are hard-disabled.** The `Stopwatch` singleton is per-process, so under Octane the toolbar would render a previous request's data. Auto-registration skips Octane as well.

Under a strict CSP the toolbar needs `style-src 'unsafe-inline'`, because it emits one scoped inline `<style>` block, and no script, external asset, or `localStorage`.
