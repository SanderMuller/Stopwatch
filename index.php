<?php declare(strict_types=1);

use Faker\Factory;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Sleep;
use SanderMuller\Stopwatch\Stopwatch;

require __DIR__ . '/vendor/autoload.php';

$faker = Factory::create('nl_NL');

$container = Container::getInstance();
$container->scoped(Stopwatch::class, fn (): Stopwatch => Stopwatch::new());

// A single shared event dispatcher — Capsule and the HTTP factory both wire into it,
// and the Stopwatch listeners (`withQueryTracking`, `withHttpTracking`) read from it.
$dispatcher = new Dispatcher($container);
$container->instance(DispatcherContract::class, $dispatcher);
$container->instance('events', $dispatcher);

// Query tracking is optional — only wire Eloquent + a SQLite scratch DB when
// `illuminate/database` is installed (it's a dev-only dep of this package).
$queryTrackingAvailable = class_exists(Capsule::class);
if ($queryTrackingAvailable) {
    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $capsule->setEventDispatcher($dispatcher);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $container->instance(DatabaseManager::class, $capsule->getDatabaseManager());

    Capsule::schema()->create('items', function (Blueprint $t): void {
        $t->id();
        $t->string('name');
        $t->integer('value');
    });
}

// HTTP tracking is also optional — only wire the Http client + faker when
// `illuminate/http` is installed (transitively present via laravel/framework).
$httpTrackingAvailable = class_exists(HttpFactory::class);
$http = null;
if ($httpTrackingAvailable) {
    $http = new HttpFactory($dispatcher);
    $http->fake([
        'api.example.com/users/*' => $http->response(['id' => 42, 'name' => 'Sander'], 200),
        'api.example.com/orders*' => $http->response(['ok' => true], 201),
        'api.example.com/inventory*' => $http->response(['stock' => 17], 200),
        'api.example.com/notify*' => $http->response('', 204),
        'api.example.com/missing*' => $http->response(['error' => 'not found'], 404),
        'api.example.com/flaky*' => $http->response('boom', 500),
        '*' => $http->response(['ok' => true], 200), // catch-all so unknown URLs don't hit the network
    ]);
}

$sw = stopwatch()
    ->withMemoryTracking()
    ->when($queryTrackingAvailable, fn (Stopwatch $sw) => $sw->withQueryTracking())
    ->when($httpTrackingAvailable, fn (Stopwatch $sw) => $sw->withHttpTracking())
    ->start();

foreach (range(0, $faker->numberBetween(5, 13)) as $i) {
    Sleep::for($faker->boolean(20) ? $faker->numberBetween(45, 770) : $faker->numberBetween(1, 45))->milliseconds();

    if ($queryTrackingAvailable) {
        if ($faker->boolean(35)) {
            foreach (range(1, $faker->numberBetween(1, 5)) as $_) {
                Capsule::table('items')->insert(['name' => $faker->word(), 'value' => $faker->numberBetween(1, 1000)]);
            }
        }
        if ($faker->boolean(35)) {
            Capsule::table('items')->where('value', '>', $faker->numberBetween(1, 500))->get();
        }
    }

    if ($httpTrackingAvailable && $http !== null && $faker->boolean(25)) {
        $endpoints = [
            ['get',  'https://api.example.com/users/' . $faker->numberBetween(1, 100) . '?token=secret'],
            ['post', 'https://api.example.com/orders'],
            ['get',  'https://api.example.com/inventory?warehouse=' . $faker->randomLetter()],
            ['post', 'https://api.example.com/notify'],
            ['get',  'https://api.example.com/missing'],
            ['get',  'https://api.example.com/flaky'],
        ];
        foreach (range(1, $faker->numberBetween(1, 4)) as $_) {
            [$method, $url] = $faker->randomElement($endpoints);
            $http->{$method}($url);
        }
    }

    $meta = $faker->boolean(17)
        ? collect([
            'id' => $faker->numberBetween(1, 1000),
            'trace' => $faker->uuid(),
            'meta' => $faker->words(2, true),
            'ref' => $faker->text($faker->numberBetween(5, 20)),
        ])->random($faker->numberBetween(1, 3), true)->all()
        : null;

    stopwatch()->checkpoint($faker->sentence($faker->numberBetween(1, 7)), $meta);
}

Sleep::for($faker->numberBetween(1, 200))->milliseconds();

echo stopwatch()->render();
