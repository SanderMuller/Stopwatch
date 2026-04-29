<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

/**
 * Three-case path normaliser for run-log persistence.
 *
 * Privacy note: never returns a raw absolute path. Hosts where Composer's vendor
 * lives outside the project (system-wide installs, monorepos, deployed PHARs) and
 * unrelated PHP files get a `<external>/<basename>` placeholder instead of a path
 * that would disclose the host filesystem layout.
 *
 * 1. Path is under `base_path()` (when resolvable) → strip the base prefix and the
 *    leading separator. `app/Http/Controllers/OrderController.php`.
 * 2. Path contains a `/vendor/` segment (Composer convention) → emit `vendor/...`.
 * 3. Otherwise → `<external>/<basename>`.
 *
 * Windows backslash separators are normalised to forward slashes before matching.
 */
final class PathRelativiser
{
    public static function relativise(string $absolutePath): string
    {
        if ($absolutePath === '') {
            return $absolutePath;
        }

        $normalised = str_replace('\\', '/', $absolutePath);

        $projectRelative = self::tryProjectRelative($normalised);

        if ($projectRelative !== null) {
            return $projectRelative;
        }

        $vendorRelative = self::tryVendorRelative($normalised);

        if ($vendorRelative !== null) {
            return $vendorRelative;
        }

        return '<external>/' . basename($normalised);
    }

    private static function tryProjectRelative(string $normalised): ?string
    {
        if (! function_exists('base_path')) {
            return null;
        }

        try {
            $base = rtrim(str_replace('\\', '/', base_path()), '/');
        } catch (\Throwable) {
            return null;
        }

        if ($base === '' || ! str_starts_with($normalised, $base . '/')) {
            return null;
        }

        return substr($normalised, strlen($base) + 1);
    }

    private static function tryVendorRelative(string $normalised): ?string
    {
        $vendorAt = strpos($normalised, '/vendor/');

        if ($vendorAt === false) {
            return null;
        }

        // Strip everything up to and including the leading `/`, leaving `vendor/...`.
        return substr($normalised, $vendorAt + 1);
    }
}
