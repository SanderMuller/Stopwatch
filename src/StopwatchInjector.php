<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

use Symfony\Component\HttpFoundation\Response;

final readonly class StopwatchInjector
{
    public const string MARKER = '<!--stopwatch-toolbar-->';

    public function __construct(
        private StopwatchToolbarRenderer $renderer,
    ) {}

    public function inject(Response $response): void
    {
        $body = $response->getContent();

        if ($body === false || $body === '' || str_contains($body, self::MARKER)) {
            return;
        }

        $pos = strripos($body, '</body>');

        if ($pos === false) {
            return;
        }

        $toolbar = $this->renderer->render();
        $newBody = substr_replace($body, $toolbar, $pos, 0);

        $response->setContent($newBody);

        $response->headers->remove('Content-Length');
        $response->headers->remove('ETag');
        $response->headers->remove('Last-Modified');
    }
}
