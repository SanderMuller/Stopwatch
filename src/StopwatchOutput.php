<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

enum StopwatchOutput: string
{
    case Silent = 'silent';
    case Log = 'log';
    case Stderr = 'stderr';
    case Dump = 'dump';
}
