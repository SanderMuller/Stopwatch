<?php declare(strict_types=1);

use SanderMuller\Stopwatch\Stopwatch;

if (! function_exists('stopwatch')) {
    function stopwatch(): Stopwatch
    {
        return app(Stopwatch::class);
    }
}
