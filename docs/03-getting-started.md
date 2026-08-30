# Getting started

The common case: a request feels slow and you want to know which part of it is.

Mark the suspect path, turn on query tracking, and write the profile to the log:

```php
use App\Models\Order;
use App\Models\User;

stopwatch()->withQueryTracking()->start();

$users = User::all();
stopwatch()->checkpoint('Load users');

$orders = Order::where('status', 'pending')->get();
stopwatch()->checkpoint('Load orders');

stopwatch()->toLog('Profile:');
```

Hit the endpoint once and read `storage/logs/laravel.log`:

```
Profile:
  [3ms / 3ms]   Load users  (queries=1)
  [12ms / 15ms] Load orders (queries=1)
  Total: 15ms
```

The first number is the gap since the previous checkpoint, the second is the running total. Loading orders costs four times what loading users does, so that is where to look.

From here, add checkpoints inside the slow section to split it further, and repeat until one line owns the time.

## Next

- [Checkpoints](04-checkpoints.md): metadata, `measure()` for a closure, and where each checkpoint is emitted.
- [Query, memory and HTTP tracking](05-tracking.md): what else can hang off a checkpoint.
- [HTML report](06-html-report.md): the same profile as a card you can click through, rather than a log line.
- [Persistent run log](09-run-log.md): keep finished runs on disk so a slow request can be read after the fact.
