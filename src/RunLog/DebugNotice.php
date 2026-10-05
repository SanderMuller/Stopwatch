<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

/**
 * Tells a reader why debug mode recorded nothing: blocked in this process, or
 * blocked in another process (a test run often has another environment than the
 * command that reads the runs).
 *
 * @internal
 */
final class DebugNotice
{
    /**
     * Message for an empty result, with the blocking guard appended when there is one.
     */
    public static function emptyMessage(string $base): string
    {
        $notice = self::blocked();

        return $notice === null ? $base : $base . ' ' . $notice;
    }

    public static function blocked(): ?string
    {
        $reason = DebugMode::requested() ? DebugMode::blockedReason() : null;

        if ($reason !== null) {
            return 'Debug mode is off: ' . $reason . '.';
        }

        $note = app(RunLogStore::class)->debugBlocked();

        return $note === null ? null : "Debug mode was blocked in another process at {$note['at']}: {$note['reason']}.";
    }
}
