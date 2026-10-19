<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

/**
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
