<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\PHPStan\Data;

use SanderMuller\Stopwatch\Stopwatch;

final class Unrelated
{
    public function probe(string $label): void {}
}

function probeCalls(Stopwatch $stopwatch, Unrelated $unrelated, ?Stopwatch $maybe, mixed $unknown): void
{
    stopwatch()->probe('via helper');
    $stopwatch->probe('via instance', ['id' => 1]);
    $stopwatch->checkpoint('kept');
    $unrelated->probe('not stopwatch');
    $maybe?->probe('nullsafe on nullable');
    $maybe->probe('nullable receiver');
    $unknown->probe('mixed receiver');
}
