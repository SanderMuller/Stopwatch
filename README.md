# Stopwatch for PHP & Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/stopwatch.svg?style=flat-square)](https://packagist.org/packages/sandermuller/stopwatch)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/stopwatch/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sandermuller/stopwatch/actions/workflows/run-tests.yml)
[![GitHub PHPStan Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/stopwatch/phpstan.yml?branch=main&label=phpstan&style=flat-square)](https://github.com/sandermuller/stopwatch/actions?query=workflow%3Aphpstan+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/stopwatch.svg?style=flat-square)](https://packagist.org/packages/sandermuller/stopwatch)
[![License](https://img.shields.io/github/license/sandermuller/stopwatch.svg?style=flat-square)](LICENSE)
[![Laravel Compatibility](https://badge.laravel.cloud/badge/sandermuller/stopwatch?style=flat)](https://packagist.org/packages/sandermuller/stopwatch)

A lightweight profiler for PHP and Laravel. Add checkpoints to your code, measure closures, track queries, memory and outbound HTTP, and see where the time goes.

Reach for it when a request, command, or job feels slow and standing up an APM is more than the question deserves. Works in tests, CI, and production.

**PHP 8.3+ · Laravel 11.x / 12.x / 13.x**

## Installation

```bash
composer require sandermuller/stopwatch
```

```bash
php artisan vendor:publish --tag=stopwatch-config   # optional
```

## Quick start

```php
stopwatch()->withQueryTracking()->start();

$users  = User::all();
stopwatch()->checkpoint('Load users');

$orders = Order::where('status', 'pending')->get();
stopwatch()->checkpoint('Load orders');

stopwatch()->toLog('Profile:');
// Profile:
//   [3ms / 3ms]   Load users  (queries=1)
//   [12ms / 15ms] Load orders (queries=1)
//   Total: 15ms
```

That is the whole loop: `start`, `checkpoint`, render.

Render it as a self-contained HTML card instead:

```blade
@stopwatch
```

![The rendered card](rendered-stopwatch.png)

## Documentation

Full documentation lives at **https://sandermuller.github.io/Stopwatch/**.

- [Why Stopwatch?](https://sandermuller.github.io/Stopwatch/why-stopwatch) — what it answers, and when an APM is the better tool
- [Installation](https://sandermuller.github.io/Stopwatch/installation) · [Checkpoints](https://sandermuller.github.io/Stopwatch/checkpoints) · [Query, memory and HTTP tracking](https://sandermuller.github.io/Stopwatch/tracking)
- [HTML report](https://sandermuller.github.io/Stopwatch/html-report) · [Profiler toolbar](https://sandermuller.github.io/Stopwatch/profiler-toolbar) · [Server-Timing and Debugbar](https://sandermuller.github.io/Stopwatch/server-timing)
- [Persistent run log](https://sandermuller.github.io/Stopwatch/run-log) · [Crash diagnostics](https://sandermuller.github.io/Stopwatch/crash-diagnostics)
- [Slow-run notifications](https://sandermuller.github.io/Stopwatch/notifications) · [AI assistant integration](https://sandermuller.github.io/Stopwatch/ai-assistant) · [Standalone PHP](https://sandermuller.github.io/Stopwatch/standalone)
- [Configuration](https://sandermuller.github.io/Stopwatch/configuration) · [API](https://sandermuller.github.io/Stopwatch/api)

The sources are in [`docs/`](docs/README.md).

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for a list of recent changes.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Sander Muller](https://github.com/SanderMuller)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see the [License File](LICENSE) for more information.
