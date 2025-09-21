<?php declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Support\Sleep;

require __DIR__ . '/vendor/autoload.php';

$faker = Faker\Factory::create('nl_NL');

Container::getInstance()
    ->scoped(SanderMuller\Stopwatch\Stopwatch::class, function (): SanderMuller\Stopwatch\Stopwatch {
        return SanderMuller\Stopwatch\Stopwatch::new();
    });

stopwatch()->start();

Sleep::fake(syncWithCarbon: true);

foreach (range(0, $faker->numberBetween(4, 22)) as $i) {
    Sleep::for(
        $faker->boolean(15)
            ? $faker->numberBetween(175, 1600)
            : $faker->numberBetween(1, 175)
    )->milliseconds();

    $meta = $faker->boolean(17)
        ? collect([
            'id' => $faker->uuid(),
            'trace' => $faker->uuid(),
            'meta' => $faker->words(3, true),
            'ref' => $faker->text($faker->numberBetween(5, 20)),
        ])->random($faker->numberBetween(1, 3), true)->all()
        : null;

    stopwatch()->checkpoint($faker->sentence($faker->numberBetween(1, 7)), $meta);
}

echo stopwatch()->render();
