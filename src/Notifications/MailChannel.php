<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Notifications;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use SanderMuller\Stopwatch\Stopwatch;

final readonly class MailChannel implements StopwatchNotificationChannel
{
    public function __construct(
        private ?string $to = null,
        private ?string $subject = null,
    ) {}

    public function notify(Stopwatch $stopwatch): void
    {
        /** @var string|null $to */
        $to = $this->to ?? config('stopwatch.mail.to');

        if ($to === null) {
            return;
        }

        /** @var string $subject */
        $subject = $this->subject ?? config('stopwatch.mail.subject') ?? "Stopwatch: {$stopwatch->totalRunDurationReadable()}";

        Mail::html($stopwatch->toHtml(), static function (Message $message) use ($to, $subject): void {
            $message->to($to)->subject($subject);
        });
    }
}
