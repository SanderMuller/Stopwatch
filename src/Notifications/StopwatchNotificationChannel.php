<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Notifications;

use SanderMuller\Stopwatch\Stopwatch;

interface StopwatchNotificationChannel
{
    public function notify(Stopwatch $stopwatch): void;
}
