# Server-Timing and Debugbar

## Server-Timing header

Checkpoint timings in the browser's DevTools Network tab. Register the middleware:

```php
// bootstrap/app.php
use SanderMuller\Stopwatch\StopwatchMiddleware;

$middleware->append(StopwatchMiddleware::class);
```

It is passive: the header appears only when the stopwatch was started somewhere in your code. To start it on every request:

```php
$middleware->append(StopwatchMiddleware::autoStart());
```

Or set the header yourself:

```php
return response('OK')->header('Server-Timing', stopwatch()->toServerTiming());
```

## Laravel Debugbar

With [`fruitcake/laravel-debugbar`](https://github.com/Fruitcake/laravel-debugbar) installed, checkpoints appear as a timeline tab with a duration badge. No wiring required.
