<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Notifications;

use SanderMuller\Stopwatch\Stopwatch;

final readonly class LogChannel implements StopwatchNotificationChannel
{
    public function __construct(
        private ?string $level = null,
        private ?string $title = null,
    ) {}

    public function notify(Stopwatch $stopwatch): void
    {
        $stopwatch->toLog(title: $this->title, level: $this->level);
    }
}
