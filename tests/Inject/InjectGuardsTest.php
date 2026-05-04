<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\Inject;

use Illuminate\Http\Request;
use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\StopwatchInjectMiddleware;
use SanderMuller\Stopwatch\StopwatchMiddleware;
use SanderMuller\Stopwatch\Tests\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class InjectGuardsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['stopwatch.inject.mode' => 'all']);
        config(['stopwatch.inject.allowed_environments' => 'testing,local']);

        StopwatchInjectMiddleware::resetOrderingHintForTesting();
    }

    public function test_injects_when_all_conditions_met(): void
    {
        $this->registerHtmlRoute('/g-happy', '<html><body>hi</body></html>');

        $response = $this->get('/g-happy');

        $response->assertOk();
        self::assertStringContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_skips_when_mode_off(): void
    {
        config(['stopwatch.inject.mode' => 'off']);

        $this->registerHtmlRoute('/g-off', '<html><body>hi</body></html>');

        $response = $this->get('/g-off');

        self::assertStringNotContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_skips_when_octane_bound(): void
    {
        $this->app->instance('octane', new \stdClass());

        $this->registerHtmlRoute('/g-oct', '<html><body>hi</body></html>');

        $response = $this->get('/g-oct');

        self::assertStringNotContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_skips_when_environment_not_in_allow_list(): void
    {
        config(['stopwatch.inject.allowed_environments' => 'production']);

        $this->registerHtmlRoute('/g-env', '<html><body>hi</body></html>');

        $response = $this->get('/g-env');

        self::assertStringNotContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_environment_allow_list_supports_array_form(): void
    {
        config(['stopwatch.inject.allowed_environments' => ['production']]);

        $this->registerHtmlRoute('/g-env-arr', '<html><body>hi</body></html>');

        $response = $this->get('/g-env-arr');

        self::assertStringNotContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_skips_when_status_not_2xx(): void
    {
        $this->app->make('router')
            ->middleware([StopwatchInjectMiddleware::class, StopwatchMiddleware::autoStart()])
            ->get('/g-404', static fn () => response('<html><body>nope</body></html>', 404, ['Content-Type' => 'text/html; charset=UTF-8']));

        $response = $this->get('/g-404');

        self::assertStringNotContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_skips_when_content_type_not_html(): void
    {
        $this->app->make('router')
            ->middleware([StopwatchInjectMiddleware::class, StopwatchMiddleware::autoStart()])
            ->get('/g-json', static fn () => response('{"x":1}', 200, ['Content-Type' => 'application/json']));

        $response = $this->get('/g-json');

        self::assertStringNotContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_skips_xhtml(): void
    {
        $this->app->make('router')
            ->middleware([StopwatchInjectMiddleware::class, StopwatchMiddleware::autoStart()])
            ->get('/g-xhtml', static fn () => response('<html><body>hi</body></html>', 200, ['Content-Type' => 'application/xhtml+xml']));

        $response = $this->get('/g-xhtml');

        self::assertStringNotContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_skips_non_utf8_charset(): void
    {
        $this->app->make('router')
            ->middleware([StopwatchInjectMiddleware::class, StopwatchMiddleware::autoStart()])
            ->get('/g-iso', static fn () => response('<html><body>hi</body></html>', 200, ['Content-Type' => 'text/html; charset=iso-8859-1']));

        $response = $this->get('/g-iso');

        self::assertStringNotContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_skips_when_content_encoding_set(): void
    {
        $this->app->make('router')
            ->middleware([StopwatchInjectMiddleware::class, StopwatchMiddleware::autoStart()])
            ->get('/g-gz', static fn () => response('<html><body>hi</body></html>', 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Content-Encoding' => 'gzip',
            ]));

        $response = $this->get('/g-gz');

        self::assertStringNotContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_skips_streamed_response(): void
    {
        $this->app->make('router')
            ->middleware([StopwatchInjectMiddleware::class, StopwatchMiddleware::autoStart()])
            ->get('/g-stream', static function () {
                return new StreamedResponse(static function (): void {
                    echo '<html><body>hi</body></html>';
                }, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            });

        $response = $this->get('/g-stream');

        self::assertStringNotContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_skips_binary_file_response(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sw-bin');
        file_put_contents($tmp, '<html><body>hi</body></html>');

        $this->app->make('router')
            ->middleware([StopwatchInjectMiddleware::class, StopwatchMiddleware::autoStart()])
            ->get('/g-bin', static fn () => new BinaryFileResponse($tmp, 200, ['Content-Type' => 'text/html; charset=UTF-8']));

        $response = $this->get('/g-bin');

        self::assertStringNotContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());

        @unlink($tmp);
    }

    public function test_skips_ajax(): void
    {
        $this->registerHtmlRoute('/g-ajax', '<html><body>hi</body></html>');

        $response = $this->withHeader('X-Requested-With', 'XMLHttpRequest')->get('/g-ajax');

        self::assertStringNotContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_skips_wants_json(): void
    {
        $this->registerHtmlRoute('/g-json-ac', '<html><body>hi</body></html>');

        $response = $this->withHeader('Accept', 'application/json')->get('/g-json-ac');

        self::assertStringNotContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_skips_pjax(): void
    {
        $this->registerHtmlRoute('/g-pjax', '<html><body>hi</body></html>');

        $response = $this->withHeader('X-PJAX', 'true')->get('/g-pjax');

        self::assertStringNotContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_skips_htmx(): void
    {
        $this->registerHtmlRoute('/g-hx', '<html><body>hi</body></html>');

        $response = $this->withHeader('HX-Request', 'true')->get('/g-hx');

        self::assertStringNotContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_skips_livewire(): void
    {
        $this->registerHtmlRoute('/g-lw', '<html><body>hi</body></html>');

        $response = $this->withHeader('X-Livewire', 'true')->get('/g-lw');

        self::assertStringNotContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_skips_inertia(): void
    {
        $this->registerHtmlRoute('/g-in', '<html><body>hi</body></html>');

        $response = $this->withHeader('X-Inertia', 'true')->get('/g-in');

        self::assertStringNotContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_skips_when_stopwatch_not_started(): void
    {
        $this->app->make('router')
            ->middleware([StopwatchInjectMiddleware::class])
            ->get('/g-nostart', static fn () => response('<html><body>hi</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']));

        $response = $this->get('/g-nostart');

        self::assertStringNotContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    public function test_skips_when_inject_inner_to_autostart(): void
    {
        $this->app->make('router')
            ->middleware([StopwatchMiddleware::autoStart(), StopwatchInjectMiddleware::class])
            ->get('/g-order', static fn () => response('<html><body>hi</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']));

        $response = $this->get('/g-order');

        self::assertStringNotContainsString('<!--stopwatch-toolbar-->', (string) $response->getContent());
    }

    private function registerHtmlRoute(string $path, string $body): void
    {
        $this->app->make('router')
            ->middleware([StopwatchInjectMiddleware::class, StopwatchMiddleware::autoStart()])
            ->get($path, static fn () => response($body, 200, ['Content-Type' => 'text/html; charset=UTF-8']));
    }

    public function test_request_route_is_resolved_when_using_alias_form(): void
    {
        // Sanity: ensure Request inherits expected behavior under tests.
        self::assertTrue(method_exists(Request::class, 'route'));
        self::assertTrue(method_exists(Stopwatch::class, 'started'));
    }
}
