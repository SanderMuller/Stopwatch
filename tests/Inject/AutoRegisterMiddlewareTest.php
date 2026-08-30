<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\Inject;

use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use SanderMuller\Stopwatch\StopwatchInjectMiddleware;
use SanderMuller\Stopwatch\StopwatchMiddleware;
use SanderMuller\Stopwatch\Tests\TestCase;

final class AutoRegisterMiddlewareTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $injectConfig = [];

    private bool $bindOctane = false;

    protected function setUp(): void
    {
        parent::setUp();

        StopwatchInjectMiddleware::resetOrderingHintForTesting();
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        foreach ($this->injectConfig as $key => $value) {
            $app['config']->set('stopwatch.inject.' . $key, $value);
        }

        if ($this->bindOctane) {
            $app->instance('octane', new \stdClass());
        }
    }

    public function test_no_middleware_is_registered_while_mode_is_off(): void
    {
        self::assertSame([], $this->autoRegistered());
    }

    public function test_middleware_is_registered_when_mode_is_not_off(): void
    {
        $this->injectConfig = ['mode' => 'all', 'allowed_environments' => 'testing'];
        $this->refreshApplication();

        self::assertSame(
            [StopwatchInjectMiddleware::class, StopwatchMiddleware::autoStart()],
            $this->autoRegistered(),
        );
    }

    public function test_nothing_is_registered_outside_the_allowed_environments(): void
    {
        $this->injectConfig = ['mode' => 'all', 'allowed_environments' => 'local'];
        $this->refreshApplication();

        self::assertSame([], $this->autoRegistered());
    }

    public function test_nothing_is_registered_on_octane(): void
    {
        $this->injectConfig = ['mode' => 'all', 'allowed_environments' => 'testing'];
        $this->bindOctane = true;
        $this->refreshApplication();

        self::assertSame([], $this->autoRegistered());
    }

    public function test_auto_register_false_keeps_the_stack_untouched(): void
    {
        $this->injectConfig = ['mode' => 'all', 'allowed_environments' => 'testing', 'auto_register' => false];
        $this->refreshApplication();

        self::assertSame([], $this->autoRegistered());
    }

    public function test_injects_without_any_manual_middleware_registration(): void
    {
        $this->injectConfig = ['mode' => 'all', 'allowed_environments' => 'testing'];
        $this->refreshApplication();

        $this->app->make('router')->get('/auto-inject', static fn (): string => '<html><body>hi</body></html>');

        $response = $this->get('/auto-inject');

        $response->assertOk();
        self::assertStringContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_route_mode_still_selects_single_routes_under_auto_register(): void
    {
        $this->injectConfig = ['mode' => 'route', 'allowed_environments' => 'testing'];
        $this->refreshApplication();

        $router = $this->app->make('router');
        $router->middleware(StopwatchInjectMiddleware::ALIAS)
            ->get('/auto-route-marked', static fn (): string => '<html><body>hi</body></html>');
        $router->get('/auto-route-plain', static fn (): string => '<html><body>hi</body></html>');

        self::assertStringContainsString('<!--stopwatch-toolbar-->', (string) $this->get('/auto-route-marked')->getContent());
        self::assertStringNotContainsString('<!--stopwatch-toolbar-->', (string) $this->get('/auto-route-plain')->getContent());
    }

    public function test_injector_stays_outer_when_the_host_already_appended_autostart(): void
    {
        $this->injectConfig = ['mode' => 'all', 'allowed_environments' => 'testing'];
        $this->refreshApplication();

        // A host that also appended autostart by hand must not push it inner
        // to the injector; the kernel dedupes the entry instead.
        $this->httpKernel()->pushMiddleware(StopwatchMiddleware::autoStart());

        $this->app->make('router')->get('/auto-inject-dupe', static fn (): string => '<html><body>hi</body></html>');

        $response = $this->get('/auto-inject-dupe');

        $response->assertOk();
        self::assertStringContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    /** @return list<string> */
    private function autoRegistered(): array
    {
        $kernel = $this->httpKernel();

        return array_values(array_filter(
            [StopwatchInjectMiddleware::class, StopwatchMiddleware::autoStart()],
            $kernel->hasMiddleware(...),
        ));
    }

    private function httpKernel(): HttpKernel
    {
        $kernel = $this->app->make(HttpKernelContract::class);

        self::assertInstanceOf(HttpKernel::class, $kernel);

        return $kernel;
    }
}
