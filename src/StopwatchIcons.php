<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch;

/**
 * Inline-SVG icons used by the HTML render. Each icon uses currentColor so it
 * inherits the surrounding text color, keeping the markup email-friendly.
 *
 * @internal
 */
final class StopwatchIcons
{
    public const string DEFAULT_STYLE = 'width:11px;height:11px;display:inline-block;vertical-align:-1px;flex-shrink:0;';

    public static function db(string $style = self::DEFAULT_STYLE): string
    {
        return self::wrap('<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/><path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/>', $style);
    }

    public static function globe(string $style = self::DEFAULT_STYLE): string
    {
        return self::wrap('<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>', $style);
    }

    public static function memory(string $style = self::DEFAULT_STYLE): string
    {
        return self::wrap('<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M9 2v2M15 2v2M9 20v2M15 20v2M2 9h2M2 15h2M20 9h2M20 15h2"/>', $style);
    }

    public static function clock(string $style = self::DEFAULT_STYLE): string
    {
        return self::wrap('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>', $style);
    }

    public static function hourglass(string $style = self::DEFAULT_STYLE): string
    {
        return self::wrap('<path d="M5 22h14M5 2h14M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22M17 2v4.172a2 2 0 0 1-.586 1.414L12 12 7.586 7.586A2 2 0 0 1 7 6.172V2"/>', $style);
    }

    public static function percent(string $style = self::DEFAULT_STYLE): string
    {
        return self::wrap('<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>', $style);
    }

    public static function sun(string $style = self::DEFAULT_STYLE): string
    {
        return self::wrap('<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>', $style);
    }

    public static function moon(string $style = self::DEFAULT_STYLE): string
    {
        return self::wrap('<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>', $style);
    }

    public static function clipboard(string $style = self::DEFAULT_STYLE): string
    {
        return self::wrap('<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>', $style);
    }

    public static function check(string $style = self::DEFAULT_STYLE): string
    {
        return self::wrap('<path d="M5 12l5 5L20 7"/>', $style);
    }

    private static function wrap(string $body, string $style): string
    {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="' . $style . '">' . $body . '</svg>';
    }
}
