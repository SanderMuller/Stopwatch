# Query, memory and HTTP tracking

Each tracker attaches its metric to every checkpoint between calls. Enable per run, or from config.

## Queries

```php
stopwatch()->withQueryTracking()->start();

User::all();
stopwatch()->checkpoint('Load users'); // 1q / 2.3ms
```

Config: `STOPWATCH_TRACK_QUERIES=true`. Requires `illuminate/database`. Up to 50 statements per checkpoint are kept with bindings and per-query duration, shown when you expand a row in the [HTML report](05-html-report.md), which is what identifies the slow statement itself.

## Memory

```php
stopwatch()->withMemoryTracking()->start();

$data = loadLargeDataset();
stopwatch()->checkpoint('Load data'); // +2.4MB
```

Config: `STOPWATCH_TRACK_MEMORY=true`. The HTML card shows a delta badge with current, delta and peak on hover; plain-text output puts the delta inline.

## Outbound HTTP

```php
stopwatch()->withHttpTracking()->start();

Http::get('https://api.example.com/users');
stopwatch()->checkpoint('Sync order'); // 2h / 156ms
```

Config: `STOPWATCH_TRACK_HTTP=true`. Up to 50 call rows per checkpoint are kept (method, URL, status, duration); counts and totals stay accurate beyond that.

**Only requests through Laravel's `Http::` facade are captured.** A direct `new GuzzleHttp\Client` bypasses the event dispatcher and is invisible to the tracker — the same limitation Telescope has. Wrap those in `stopwatch()->measure()` instead.

## Combining

```php
stopwatch()->withQueryTracking()->withMemoryTracking()->withHttpTracking()->start();
```

`when()` and `unless()` toggle parts of the chain without breaking it:

```php
stopwatch()
    ->withMemoryTracking()
    ->when($trackQueries, fn ($sw) => $sw->withQueryTracking())
    ->unless(app()->runningUnitTests(), fn ($sw) => $sw->withHttpTracking())
    ->start();
```
