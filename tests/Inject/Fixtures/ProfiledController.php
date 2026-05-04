<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\Inject\Fixtures;

use Illuminate\Http\Response;
use SanderMuller\Stopwatch\ProfileViaStopwatch;

#[ProfileViaStopwatch]
final class ProfiledController
{
    public function show(): Response
    {
        return new Response('<html><body>profiled</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
