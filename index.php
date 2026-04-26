<?php declare(strict_types=1);

use Faker\Factory;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Sleep;
use SanderMuller\Stopwatch\Stopwatch;

require __DIR__ . '/vendor/autoload.php';

$faker = Factory::create('nl_NL');

$container = Container::getInstance();
$container->scoped(Stopwatch::class, fn (): Stopwatch => Stopwatch::new());

// Query tracking is optional — only wire Eloquent + a SQLite scratch DB when
// `illuminate/database` is installed (it's a dev-only dep of this package).
$queryTrackingAvailable = class_exists(Capsule::class);
if ($queryTrackingAvailable) {
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
}

$sw = stopwatch()->withMemoryTracking();
if ($queryTrackingAvailable) {
    $sw->withQueryTracking();
}
$sw->start();

foreach (range(0, $faker->numberBetween(4, 20)) as $i) {
    Sleep::for($faker->boolean(25) ? $faker->numberBetween(50, 770) : $faker->numberBetween(2, 50))->milliseconds();

    if ($queryTrackingAvailable) {
        foreach (range(1, $faker->numberBetween(0, 5)) as $_) {
            Capsule::table('items')->insert(['name' => $faker->word(), 'value' => $faker->numberBetween(1, 1000)]);
        }
        if ($faker->boolean(60)) {
            Capsule::table('items')->where('value', '>', $faker->numberBetween(1, 500))->get();
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
