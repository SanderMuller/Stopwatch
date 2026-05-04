<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class ProfileViaStopwatch {}
