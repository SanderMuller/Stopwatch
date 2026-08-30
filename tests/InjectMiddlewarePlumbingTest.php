<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests;

use Illuminate\Routing\Router;
use SanderMuller\Stopwatch\StopwatchInjectAlias;
use SanderMuller\Stopwatch\StopwatchInjectMiddleware;

final class InjectMiddlewarePlumbingTest extends TestCase
{
    public function test_inject_config_defaults(): void
    {
        $config = config('stopwatch.inject');

        self::assertIsArray($config);
        self::assertSame('off', $config['mode']);
        self::assertTrue($config['auto_register']);
        self::assertSame('local', $config['allowed_environments']);
        self::assertSame('bottom-right', $config['position']);
        self::assertSame(500, $config['slow_request_threshold_ms']);
    }

    public function test_inject_alias_resolves_to_middleware_class(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $aliases = $router->getMiddleware();

        self::assertArrayHasKey(StopwatchInjectMiddleware::ALIAS, $aliases);
        self::assertSame(StopwatchInjectAlias::class, $aliases[StopwatchInjectMiddleware::ALIAS]);
    }

    public function test_middleware_no_ops_when_mode_off(): void
    {
        config(['stopwatch.inject.mode' => 'off']);

        $this->app->make('router')
            ->middleware(StopwatchInjectMiddleware::class)
            ->get('/inject-off', static fn (): string => '<html><body>hi</body></html>');

        $response = $this->get('/inject-off');

        $response->assertOk();
        self::assertSame('<html><body>hi</body></html>', $response->getContent());
    }

    public function test_middleware_no_ops_when_alias_used_with_mode_off(): void
    {
        config(['stopwatch.inject.mode' => 'off']);

        $this->app->make('router')
            ->middleware(StopwatchInjectMiddleware::ALIAS)
            ->get('/inject-off-alias', static fn (): string => '<html><body>hi</body></html>');

        $response = $this->get('/inject-off-alias');

        $response->assertOk();
        self::assertSame('<html><body>hi</body></html>', $response->getContent());
    }
}
