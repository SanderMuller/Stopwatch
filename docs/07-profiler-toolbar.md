# Profiler toolbar

A Debugbar-style toolbar injected into eligible HTML responses: per-request duration, memory delta, query and HTTP counts, and a JavaScript-free panel of per-checkpoint deltas.

```dotenv
STOPWATCH_INJECT=all                      # off | all | route | attribute
STOPWATCH_INJECT_ENVIRONMENTS=local       # CSV, default-deny by environment name
STOPWATCH_INJECT_POSITION=bottom-right    # bottom-right | bottom-left | top-right | top-left
STOPWATCH_INJECT_SLOW_REQUEST_MS=500      # duration pill turns red at or above this
```

## Security: default-deny by environment

`STOPWATCH_INJECT_ENVIRONMENTS` defaults to `local` alone. **The expanded panel exposes raw SQL with bound values.** Staging, dev and preview environments are commonly reachable from the internet, so a `not-production` style allow-rule would publish query bindings to anyone who loads a page. Opt environments in one at a time:

```dotenv
STOPWATCH_INJECT_ENVIRONMENTS=local,docker
```

Treat any environment with the toolbar enabled as trusted-viewer-only.

## Middleware order

The injector reads aggregates after `$next` returns, so it must wrap autostart:

```php
use SanderMuller\Stopwatch\StopwatchInjectMiddleware;
use SanderMuller\Stopwatch\StopwatchMiddleware;

$middleware->append(StopwatchInjectMiddleware::class);   // outer, runs after()
$middleware->append(StopwatchMiddleware::autoStart());   // inner, finishes the stopwatch
```

Reversed, injection silently no-ops. Outside production the middleware logs a one-shot debug line to flag it.

## Modes

- **`all`**: every eligible HTML response.
- **`route`**: only routes carrying the `stopwatch.inject` alias:
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
<summary>Eligibility guards</summary>

Injection is skipped for: non-2xx responses; a `Content-Type` that is not `text/html` (or a charset that is not UTF-8); `Content-Encoding` set to anything but `identity`; `StreamedResponse` and `BinaryFileResponse`; ajax, `wantsJson`, pjax, `HX-Request`, `X-Livewire` and `X-Inertia` requests; and any run where the stopwatch was never started or never finished. XHTML (`application/xhtml+xml`) is not supported.

</details>

**Octane and Swoole are hard-disabled.** The `Stopwatch` singleton is per-process, so under Octane the toolbar would render a previous request's data.

Under a strict CSP the toolbar needs `style-src 'unsafe-inline'`, because it emits one scoped inline `<style>` block, and no script, external asset, or `localStorage`.
