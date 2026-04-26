<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use SanderMuller\Stopwatch\FakeClock;
use SanderMuller\Stopwatch\Notifications\LogChannel;
use SanderMuller\Stopwatch\Notifications\MailChannel;
use SanderMuller\Stopwatch\Notifications\StopwatchNotificationChannel;
use SanderMuller\Stopwatch\Stopwatch;

final class StopwatchNotificationTest extends TestCase
{
    public function test_notify_if_slower_than_notifies_on_finish(): void
    {
        Log::spy();

        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->notifyUsing([LogChannel::class]);
        $stopwatch->notifyIfSlowerThan(50);
        $stopwatch->start();
        $clock->advance(100);
        $stopwatch->checkpoint('Slow work');

        self::assertFalse($stopwatch->ended());

        $stopwatch->finish();

        self::assertTrue($stopwatch->ended());
        Log::shouldHaveReceived('log')->atLeast()->once();
    }

    public function test_notify_if_slower_than_skips_when_under_threshold(): void
    {
        Log::spy();

        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->notifyUsing([LogChannel::class]);
        $stopwatch->notifyIfSlowerThan(500);
        $stopwatch->start();
        $clock->advance(10);
        $stopwatch->checkpoint('Fast work');
        $stopwatch->finish();

        self::assertTrue($stopwatch->ended());
        Log::shouldNotHaveReceived('log');
    }

    public function test_notify_if_slower_than_triggers_on_implicit_finish(): void
    {
        Log::spy();

        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->notifyUsing([LogChannel::class]);
        $stopwatch->notifyIfSlowerThan(50);
        $stopwatch->start();
        $clock->advance(100);
        $stopwatch->checkpoint('Slow work');

        // toArray() implicitly calls finish()
        $stopwatch->toArray();

        Log::shouldHaveReceived('log')->atLeast()->once();
    }

    public function test_notify_if_slower_than_returns_self_when_disabled(): void
    {
        $stopwatch = Stopwatch::new();
        $stopwatch->disable();

        $result = $stopwatch->notifyIfSlowerThan(1);

        self::assertInstanceOf(Stopwatch::class, $result);
    }

    public function test_notify_if_slower_than_uses_custom_channel(): void
    {
        $called = false;

        $channel = new class ($called) implements StopwatchNotificationChannel {
            public function __construct(private bool &$called) {}

            public function notify(Stopwatch $stopwatch): void
            {
                $this->called = true;
            }
        };

        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->notifyUsing([$channel]);
        $stopwatch->notifyIfSlowerThan(10);
        $stopwatch->start();
        $clock->advance(50);
        $stopwatch->checkpoint('Work');
        $stopwatch->finish();

        self::assertTrue($called);
    }

    public function test_notify_if_slower_than_does_nothing_without_channels(): void
    {
        Log::spy();

        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->notifyUsing([]);
        $stopwatch->notifyIfSlowerThan(10);
        $stopwatch->start();
        $clock->advance(50);
        $stopwatch->checkpoint('Work');
        $stopwatch->finish();

        Log::shouldNotHaveReceived('log');
    }

    public function test_mail_channel_sends_email_on_finish(): void
    {
        Mail::spy();

        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->notifyUsing([new MailChannel(to: 'admin@example.com')]);
        $stopwatch->notifyIfSlowerThan(100);
        $stopwatch->start();
        $clock->advance(200);
        $stopwatch->checkpoint('Slow operation');
        $stopwatch->finish();

        Mail::shouldHaveReceived('html')->once();
    }

    public function test_mail_channel_skips_when_no_recipient(): void
    {
        Mail::spy();

        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->notifyUsing([new MailChannel()]);
        $stopwatch->notifyIfSlowerThan(100);
        $stopwatch->start();
        $clock->advance(200);
        $stopwatch->checkpoint('Slow operation');
        $stopwatch->finish();

        Mail::shouldNotHaveReceived('html');
    }

    public function test_notify_threshold_persists_across_restart(): void
    {
        Log::spy();

        $clock = new FakeClock();
        $stopwatch = Stopwatch::new(clock: $clock);
        $stopwatch->notifyUsing([LogChannel::class]);
        $stopwatch->notifyIfSlowerThan(50);
        $stopwatch->start();
        $clock->advance(100);
        $stopwatch->checkpoint('First run');
        $stopwatch->finish();

        Log::shouldHaveReceived('log')->atLeast()->once();

        // Restart — threshold persists
        Log::spy();
        $stopwatch->restart();
        $clock->advance(100);
        $stopwatch->checkpoint('Second run');
        $stopwatch->finish();

        Log::shouldHaveReceived('log')->atLeast()->once();
    }
}
