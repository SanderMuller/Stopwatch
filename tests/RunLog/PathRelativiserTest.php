<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\RunLog;

use SanderMuller\Stopwatch\RunLog\PathRelativiser;
use SanderMuller\Stopwatch\Tests\TestCase;

final class PathRelativiserTest extends TestCase
{
    public function test_strips_base_path_prefix_when_under_project(): void
    {
        $base = base_path();
        $path = $base . '/app/Http/Controllers/OrderController.php';

        self::assertSame('app/Http/Controllers/OrderController.php', PathRelativiser::relativise($path));
    }

    public function test_returns_vendor_relative_when_path_under_any_vendor_segment(): void
    {
        // Outside base_path() but inside a /vendor/ tree (e.g. system-wide composer).
        $path = '/usr/local/share/php/vendor/laravel/framework/src/Illuminate/Routing/Controller.php';

        self::assertSame('vendor/laravel/framework/src/Illuminate/Routing/Controller.php', PathRelativiser::relativise($path));
    }

    public function test_falls_back_to_external_basename_for_unrelated_paths(): void
    {
        // Privacy: never persist a raw absolute path that discloses host filesystem layout.
        self::assertSame('<external>/random.php', PathRelativiser::relativise('/usr/lib/php/random.php'));
        self::assertSame('<external>/foo.php', PathRelativiser::relativise('/tmp/foo.php'));
    }

    public function test_normalises_windows_backslashes_to_forward_slashes(): void
    {
        // Stopwatch dev tooling sometimes runs on Windows. Backslashes must normalise.
        $base = base_path();
        $windowsStyle = str_replace('/', '\\', $base . '/app/Foo.php');

        self::assertSame('app/Foo.php', PathRelativiser::relativise($windowsStyle));
    }

    public function test_returns_empty_string_unchanged(): void
    {
        self::assertSame('', PathRelativiser::relativise(''));
    }

    public function test_prefers_project_relative_over_vendor_when_both_match(): void
    {
        // base_path() resolves AND the path also contains /vendor/ — project-relative wins.
        $base = base_path();
        $path = $base . '/vendor/some/package/Foo.php';

        // Without case 1, this would be `vendor/some/package/Foo.php` either way; the test
        // documents the priority: case 1 strips the project prefix even when /vendor/ is inside.
        self::assertSame('vendor/some/package/Foo.php', PathRelativiser::relativise($path));
    }
}
