# Standalone PHP

The profiler core does not need Laravel:

```php
$stopwatch = \SanderMuller\Stopwatch\Stopwatch::new();
$stopwatch->start();
$stopwatch->checkpoint('Done');
echo $stopwatch->toString();
```

What is Laravel-only:

| Feature | Needs |
|---|---|
| `stopwatch()` helper | the container |
| Query tracking | `illuminate/database` and an application |
| Config-based setup, channel resolution from class strings | the container |
| Toolbar, `Server-Timing` middleware, Debugbar tab, run log | an application |
