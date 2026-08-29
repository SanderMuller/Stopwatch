# Why Stopwatch?

A request feels slow and the log says how long it took, not where the time went. Stopwatch answers the second question: mark a few points along the path, and read the gaps between them.

```php
stopwatch()->withQueryTracking()->start();

$users = User::all();
stopwatch()->checkpoint('Load users');

$orders = Order::where('status', 'pending')->get();
stopwatch()->checkpoint('Load orders');

stopwatch()->toLog('Profile:');
// Profile:
//   [3ms / 3ms]   Load users  (queries=1)
//   [12ms / 15ms] Load orders (queries=1)
//   Total: 15ms
```

Reach for it when a request, command, or job feels slow and standing up an APM is more than the question deserves. It works in tests, in CI, and in production.

## What it is not

An APM. There is no aggregation across requests, no service map, no alerting on trends. Stopwatch profiles the run in front of you, and the [run log](08-run-log.md) keeps the last few so you can read them after the fact.

## Compatibility

PHP 8.3+ · Laravel 11.x / 12.x / 13.x. The profiler core runs [without Laravel](12-standalone.md); query tracking, the toolbar, and the run log do not.
