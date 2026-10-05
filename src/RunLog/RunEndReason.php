<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

/**
 * Why an active run ended, as reported to {@see CheckpointListener::onRunEnd()}.
 *
 * @internal
 */
enum RunEndReason: string
{
    case Finished = 'finished';
    case Restarted = 'restarted';
    case Shutdown = 'shutdown';
}
