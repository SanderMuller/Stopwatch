<?php declare(strict_types=1);

/**
 * Deterministic Stopwatch render for the README screenshot.
 *
 * Render to HTML and snap with headless Chrome:
 *   php snap.php > /tmp/stopwatch-shot.html
 *   /Applications/Google\ Chrome.app/Contents/MacOS/Google\ Chrome --headless --disable-gpu \
 *     --screenshot=rendered-stopwatch.png --window-size=1140,820 --hide-scrollbars \
 *     --virtual-time-budget=3000 --force-prefers-reduced-motion \
 *     file:///tmp/stopwatch-shot.html
 */

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use SanderMuller\Stopwatch\FakeClock;
use SanderMuller\Stopwatch\Stopwatch;

require __DIR__ . '/vendor/autoload.php';

$clock = new FakeClock();
$container = Container::getInstance();
$container->scoped(Stopwatch::class, fn (): Stopwatch => Stopwatch::new($clock));

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->setEventDispatcher(new Dispatcher($container));
$capsule->setAsGlobal();
$capsule->bootEloquent();
$container->instance(DatabaseManager::class, $capsule->getDatabaseManager());

Capsule::schema()->create('items', function (Blueprint $t): void {
    $t->id();
    $t->string('name');
    $t->integer('value');
});

$sw = stopwatch()->withMemoryTracking()->withQueryTracking()->start();

$conn = $capsule->getDatabaseManager()->connection();

$rows = [
    // [label, advanceMs, queries, queryTotalMs, memAllocBytes, meta]
    ['Validate request',          12,  1,   1.2,         0, null],
    ['Load user + permissions',   45,  3,   8.4,    250000, null],
    ['Fetch order detail',        26,  2,   4.1,    180000, ['order_id' => 'ORD-9248', 'currency' => 'EUR']],
    ['Apply business rules',      33,  4,   6.7,    120000, null],
    ['Render PDF',               380, 12, 142.7, 12_500_000, ['template' => 'invoice', 'pages' => 14, 'paper' => 'A4']],
    ['Send confirmation email',   19,  1,   2.8,     50000, null],
    ['Upload to S3',              78,  0,   0,    -8_000_000, ['bucket' => 'invoices-prod']],
];

$ballast = [];

foreach ($rows as $idx => [$label, $advance, $queries, $queryMs, $memAlloc, $meta]) {
    $clock->advance($advance);

    if ($queries > 0) {
        $perQuery = $queryMs / $queries;
        for ($i = 0; $i < $queries; $i++) {
            $conn->getEventDispatcher()->dispatch(new QueryExecuted('select * from items where id = ?', [$i], $perQuery, $conn));
        }
    }

    if ($memAlloc > 0) {
        $ballast[$idx] = str_repeat('x', $memAlloc);
    } elseif ($memAlloc < 0) {
        $ballast = array_slice($ballast, 0, -1, true);
    }

    $sw->checkpoint($label, $meta);
}

$clock->advance(12);

$cardHtml = $sw->render()->toHtml();
$lightCard = str_replace('class="sw-stopwatch"', 'class="sw-stopwatch" data-theme="light"', $cardHtml);
$darkCard = str_replace('class="sw-stopwatch"', 'class="sw-stopwatch" data-theme="dark"', $cardHtml);

?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { margin: 0; padding: 30px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
  .stage { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start; }
  .panel { padding: 16px; border-radius: 16px; }
  .panel.light { background: #e5e7eb; }
  .panel.dark  { background: #000000; }
  .panel h2 { margin: 0 0 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; }
  .panel.light h2 { color: #6b7280; }
  .panel.dark h2  { color: #94a3b8; }

  /* Allow card to expand past max-height for the screenshot. */
  .sw-stopwatch > div[style*="max-height"] { max-height: none !important; overflow: visible !important; }

  /* Demo: force-hover the slowest row (Render PDF) so the screenshot showcases hover state + tooltip. */
  .sw-stopwatch .sw-row:nth-child(5) {
    background: var(--sw-slow-bg) !important;
    /* Mirror .sw-slow:hover var overrides — real :hover is not triggered by the headless screenshot. */
    --sw-text: #0f172a !important;
    --sw-chip-bg: #fff !important;
    --sw-chip-key: #94a3b8 !important;
    --sw-chip-val: #334155 !important;
  }
  .sw-stopwatch .sw-row:nth-child(5) .sw-bar-fill {
    box-shadow: 0 0 0 1px var(--sw-bar), 0 0 8px var(--sw-bar) !important;
    filter: brightness(1.05) !important;
  }
  .sw-stopwatch .sw-row:nth-child(5) .sw-tip {
    display: block !important;
    opacity: 1 !important;
    transform: translateY(0) !important;
  }
  /* Force-show the matching segment in the overview bar so the screenshot also
     demos the segment-hover state + tooltip + arrow. */
  .sw-stopwatch .sw-seg[data-sw-tip="4"] {
    filter: brightness(1.25) saturate(1.25);
    box-shadow: 0 0 0 2px var(--sw-active-ring,#fff);
    transform: scaleY(1.5);
    z-index: 2;
  }
  .sw-stopwatch .sw-seg-tip[data-sw-tip="4"] {
    display: block !important;
    opacity: 1 !important;
    transform: translateY(0) !important;
  }

  /* Theme is forced per panel via data-theme="light"|"dark" on the card itself — see PHP above. */
</style>
</head>
<body>
<div class="stage">
  <div class="panel light">
    <h2>Light</h2>
    <?= $lightCard ?>
  </div>
  <div class="panel dark">
    <h2>Dark</h2>
    <?= $darkCard ?>
  </div>
</div>
</body>
</html>
