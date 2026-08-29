# Installation

```bash
composer require sandermuller/stopwatch
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag=stopwatch-config
```

## First profile

Drop checkpoints around suspect code and render:

```php
stopwatch()->start();

$users = User::all();
stopwatch()->checkpoint('Load users');

stopwatch()->toLog('Profile:');
```

That is the whole loop: `start`, `checkpoint`, render. Nothing else is required — `checkpoint()` auto-starts the stopwatch if you skip `start()`.

## Where to go next

- [Checkpoints](03-checkpoints.md) — the measuring API.
- [Tracking](04-tracking.md) — attach query, memory and HTTP metrics.
- [HTML report](05-html-report.md) and the [toolbar](06-profiler-toolbar.md) — read a profile in the browser.
- [Run log](08-run-log.md) — keep runs on disk for later.
