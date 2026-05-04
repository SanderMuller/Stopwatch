<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\Inject\Fixtures;

use Illuminate\Http\Response;
use SanderMuller\Stopwatch\ProfileViaStopwatch;

#[ProfileViaStopwatch]
final class InvokableProfiledController
{
    public function __invoke(): Response
    {
        return new Response('<html><body>invokable</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
