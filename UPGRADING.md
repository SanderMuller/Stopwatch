# Upgrading from Stopwatch 0.2.x to 0.3.x

## PHP version requirements

Stopwatch now uses PHP 8.3 or newer to run.

## Breaking changes

`Stopwatch::start()` is replaced by `stopwatch()->start()`, or if you prefer the non helper method, you can use `Stopwatch::new()->start()`.
