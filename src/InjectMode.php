<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

/**
 * The `stopwatch.inject.mode` setting. A value that is not one of the cases,
 * such as `false`, `null`, `true` or a misspelling, reads as {@see self::Off}.
 *
 * @internal
 */
enum InjectMode: string
{
    case Off = 'off';
    case All = 'all';
    case Route = 'route';
    case Attribute = 'attribute';

    public static function fromConfig(mixed $value): self
    {
        return is_string($value) ? self::tryFrom($value) ?? self::Off : self::Off;
    }
}
