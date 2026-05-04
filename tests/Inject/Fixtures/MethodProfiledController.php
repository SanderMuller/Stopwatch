<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\Inject\Fixtures;

use Illuminate\Http\Response;
use SanderMuller\Stopwatch\ProfileViaStopwatch;

final class MethodProfiledController
{
    #[ProfileViaStopwatch]
    public function show(): Response
    {
        return new Response('<html><body>method-profiled</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function unmarked(): Response
    {
        return new Response('<html><body>unmarked</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
