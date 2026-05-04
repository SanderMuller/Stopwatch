<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\Inject;

use Illuminate\Http\Response;
use SanderMuller\Stopwatch\Stopwatch;
use SanderMuller\Stopwatch\StopwatchInjector;
use SanderMuller\Stopwatch\StopwatchToolbarRenderer;
use SanderMuller\Stopwatch\Tests\TestCase;

final class InjectorTest extends TestCase
{
    public function test_injects_toolbar_before_closing_body(): void
    {
        $injector = $this->makeInjector();

        $response = new Response('<html><body>hello</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $injector->inject($response);

        $body = (string) $response->getContent();

        self::assertStringContainsString('<!--stopwatch-toolbar-->', $body);
        self::assertStringContainsString('hello', $body);
        self::assertStringEndsWith('</body></html>', $body);
        self::assertSame(1, substr_count($body, '<!--stopwatch-toolbar-->'));
    }

    public function test_idempotent_when_marker_already_present(): void
    {
        $injector = $this->makeInjector();

        $response = new Response('<html><body>x</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $injector->inject($response);
        $first = (string) $response->getContent();

        $injector->inject($response);
        $second = (string) $response->getContent();

        self::assertSame($first, $second);
        self::assertSame(1, substr_count($second, '<!--stopwatch-toolbar-->'));
    }

    public function test_no_op_when_no_closing_body(): void
    {
        $injector = $this->makeInjector();

        $response = new Response('<div>fragment</div>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $injector->inject($response);

        self::assertSame('<div>fragment</div>', $response->getContent());
    }

    public function test_picks_last_closing_body_tag(): void
    {
        $injector = $this->makeInjector();

        $body = '<html><body>outer<template></body></template>real</body></html>';
        $response = new Response($body, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $injector->inject($response);

        $newBody = (string) $response->getContent();
        $markerPos = strpos($newBody, '<!--stopwatch-toolbar-->');
        $lastBodyPos = strripos($newBody, '</body>');

        self::assertNotFalse($markerPos);
        self::assertNotFalse($lastBodyPos);
        self::assertLessThan($lastBodyPos, $markerPos);
    }

    public function test_preserves_multibyte_content_byte_exact(): void
    {
        $injector = $this->makeInjector();

        $cjk = '日本語テスト';
        $emoji = '😀🎉🚀';
        $body = '<html><body>' . $cjk . ' ' . $emoji . '</body></html>';
        $response = new Response($body, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $injector->inject($response);

        $newBody = (string) $response->getContent();

        self::assertStringContainsString($cjk, $newBody);
        self::assertStringContainsString($emoji, $newBody);
        self::assertStringContainsString('<!--stopwatch-toolbar-->', $newBody);
    }

    public function test_strips_body_derived_headers_after_injection(): void
    {
        $injector = $this->makeInjector();

        $response = new Response('<html><body>x</body></html>', 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Length' => '999',
            'ETag' => '"abc"',
            'Last-Modified' => 'Wed, 21 Oct 2026 07:28:00 GMT',
        ]);

        $injector->inject($response);

        self::assertFalse($response->headers->has('Content-Length'));
        self::assertFalse($response->headers->has('ETag'));
        self::assertFalse($response->headers->has('Last-Modified'));
    }

    public function test_renderer_emits_collapsed_pills_and_panel(): void
    {
        $stopwatch = $this->app->make(Stopwatch::class);
        $stopwatch->start();
        $stopwatch->checkpoint('First');
        $stopwatch->checkpoint('Second');
        $stopwatch->finish();

        $renderer = new StopwatchToolbarRenderer($stopwatch);
        $html = $renderer->render();

        self::assertStringContainsString('<!--stopwatch-toolbar-->', $html);
        self::assertStringContainsString('id="stopwatch-toolbar"', $html);
        self::assertStringContainsString('sw-pos-bottom-right', $html);
        self::assertStringContainsString('sw-pill', $html);
        self::assertStringContainsString('First', $html);
        self::assertStringContainsString('Second', $html);
        self::assertStringContainsString('<table class="sw-table">', $html);
    }

    public function test_renderer_marks_pill_slow_when_over_threshold(): void
    {
        config(['stopwatch.inject.slow_request_threshold_ms' => 0]);

        $stopwatch = $this->app->make(Stopwatch::class);
        $stopwatch->start();
        $stopwatch->checkpoint('Only');
        $stopwatch->finish();

        $renderer = new StopwatchToolbarRenderer($stopwatch);
        $html = $renderer->render();

        self::assertStringContainsString('sw-pill-slow', $html);
    }

    public function test_renderer_position_class_reflects_config(): void
    {
        config(['stopwatch.inject.position' => 'top-left']);

        $stopwatch = $this->app->make(Stopwatch::class);
        $stopwatch->start();
        $stopwatch->finish();

        $renderer = new StopwatchToolbarRenderer($stopwatch);

        self::assertStringContainsString('sw-pos-top-left', $renderer->render());
    }

    public function test_renderer_falls_back_to_bottom_right_for_unknown_position(): void
    {
        config(['stopwatch.inject.position' => 'middle-of-page']);

        $stopwatch = $this->app->make(Stopwatch::class);
        $stopwatch->start();
        $stopwatch->finish();

        $renderer = new StopwatchToolbarRenderer($stopwatch);

        self::assertStringContainsString('sw-pos-bottom-right', $renderer->render());
    }

    private function makeInjector(): StopwatchInjector
    {
        $stopwatch = $this->app->make(Stopwatch::class);
        $stopwatch->start();
        $stopwatch->finish();

        return new StopwatchInjector(new StopwatchToolbarRenderer($stopwatch));
    }
}
