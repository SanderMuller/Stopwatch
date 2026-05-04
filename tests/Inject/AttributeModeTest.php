<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\Inject;

use Illuminate\Support\Facades\Log;
use SanderMuller\Stopwatch\StopwatchInjectMiddleware;
use SanderMuller\Stopwatch\StopwatchMiddleware;
use SanderMuller\Stopwatch\Tests\Inject\Fixtures\InvokableProfiledController;
use SanderMuller\Stopwatch\Tests\Inject\Fixtures\MethodProfiledController;
use SanderMuller\Stopwatch\Tests\Inject\Fixtures\ProfiledController;
use SanderMuller\Stopwatch\Tests\TestCase;

final class AttributeModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['stopwatch.inject.mode' => 'attribute']);
        config(['stopwatch.inject.allowed_environments' => 'testing,local']);

        StopwatchInjectMiddleware::resetOrderingHintForTesting();
    }

    public function test_class_level_attribute_triggers_injection(): void
    {
        $this->app->make('router')
            ->middleware([StopwatchInjectMiddleware::class, StopwatchMiddleware::autoStart()])
            ->get('/attr-class', [ProfiledController::class, 'show']);

        $response = $this->get('/attr-class');

        self::assertStringContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_method_level_attribute_triggers_injection(): void
    {
        $this->app->make('router')
            ->middleware([StopwatchInjectMiddleware::class, StopwatchMiddleware::autoStart()])
            ->get('/attr-method', [MethodProfiledController::class, 'show']);

        $response = $this->get('/attr-method');

        self::assertStringContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_unmarked_method_skips_injection(): void
    {
        $this->app->make('router')
            ->middleware([StopwatchInjectMiddleware::class, StopwatchMiddleware::autoStart()])
            ->get('/attr-none', [MethodProfiledController::class, 'unmarked']);

        $response = $this->get('/attr-none');

        self::assertStringNotContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_invokable_controller_attribute_triggers_injection(): void
    {
        $this->app->make('router')
            ->middleware([StopwatchInjectMiddleware::class, StopwatchMiddleware::autoStart()])
            ->get('/attr-invokable', InvokableProfiledController::class);

        $response = $this->get('/attr-invokable');

        self::assertStringContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_string_action_form_honored(): void
    {
        $this->app->make('router')
            ->middleware([StopwatchInjectMiddleware::class, StopwatchMiddleware::autoStart()])
            ->get('/attr-string', ProfiledController::class . '@show');

        $response = $this->get('/attr-string');

        self::assertStringContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_closure_route_skips_unless_alias_added(): void
    {
        $router = $this->app->make('router');

        $router->middleware([StopwatchInjectMiddleware::class, StopwatchMiddleware::autoStart()])
            ->get('/attr-closure', static fn () => response('<html><body>x</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']));

        $response = $this->get('/attr-closure');

        self::assertStringNotContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_closure_route_with_alias_injects_under_attribute_mode(): void
    {
        $router = $this->app->make('router');

        $router->middleware([StopwatchInjectMiddleware::class, StopwatchMiddleware::autoStart(), StopwatchInjectMiddleware::ALIAS])
            ->get('/attr-closure-alias', static fn () => response('<html><body>x</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']));

        $response = $this->get('/attr-closure-alias');

        self::assertStringContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_route_mode_does_not_read_attributes(): void
    {
        config(['stopwatch.inject.mode' => 'route']);

        $this->app->make('router')
            ->middleware([StopwatchInjectMiddleware::class, StopwatchMiddleware::autoStart()])
            ->get('/route-mode-attr', [ProfiledController::class, 'show']);

        $response = $this->get('/route-mode-attr');

        self::assertStringNotContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_route_mode_with_alias_injects(): void
    {
        config(['stopwatch.inject.mode' => 'route']);

        $this->app->make('router')
            ->middleware([StopwatchInjectMiddleware::class, StopwatchMiddleware::autoStart(), StopwatchInjectMiddleware::ALIAS])
            ->get('/route-mode-alias', static fn () => response('<html><body>x</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']));

        $response = $this->get('/route-mode-alias');

        self::assertStringContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_alias_does_not_trigger_ordering_warning_or_double_inject(): void
    {
        config(['stopwatch.inject.mode' => 'route']);

        $this->app->make('router')
            ->middleware([StopwatchInjectMiddleware::class, StopwatchMiddleware::autoStart(), StopwatchInjectMiddleware::ALIAS])
            ->get('/route-mode-alias-no-warn', static fn () => response('<html><body>x</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']));

        $logged = [];
        Log::shouldReceive('debug')
            ->andReturnUsing(function (string $message) use (&$logged): void {
                $logged[] = $message;
            });

        $response = $this->get('/route-mode-alias-no-warn');

        $body = (string) $response->getContent();

        self::assertSame(1, substr_count($body, '<!--stopwatch-toolbar-->'), 'toolbar must be injected exactly once');
        self::assertSame([], array_filter($logged, static fn (string $msg) => str_contains($msg, 'StopwatchInjectMiddleware skipped')), 'route alias must not trip the ordering warning');
    }
}
