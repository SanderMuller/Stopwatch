<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests;

use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every env var and stopwatch command that an agent-facing text names must
 * exist, so a skill or docs page cannot send an agent to a dead end.
 */
final class DocsReferencesTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function agentFacingFiles(): iterable
    {
        $root = dirname(__DIR__);

        foreach ([
            'resources/boost/skills/stopwatch-debug/SKILL.md',
            'resources/boost/skills/stopwatch-profile/SKILL.md',
            'docs/04-checkpoints.md',
            'docs/09-run-log.md',
            'docs/11-debugging.md',
            'docs/13-ai-assistant.md',
            'docs/15-configuration.md',
            'docs/16-api.md',
            'docs/llms-intro.md',
        ] as $file) {
            yield $file => [$root . '/' . $file];
        }
    }

    #[DataProvider('agentFacingFiles')]
    public function test_named_env_vars_exist_in_the_config(string $file): void
    {
        $config = (string) file_get_contents(dirname(__DIR__) . '/config/stopwatch.php');
        preg_match_all('/STOPWATCH_[A-Z_]+/', (string) file_get_contents($file), $matches);

        $missing = array_values(array_filter(
            array_unique($matches[0]),
            static fn (string $env): bool => ! str_contains($config, "'{$env}'"),
        ));

        self::assertSame([], $missing, "Named in {$file} but not read in config/stopwatch.php");
    }

    #[DataProvider('agentFacingFiles')]
    public function test_named_commands_are_registered(string $file): void
    {
        $registered = array_keys($this->app->make(Kernel::class)->all());
        preg_match_all('/stopwatch:runs:[a-z]+/', (string) file_get_contents($file), $matches);

        $missing = array_values(array_diff(array_unique($matches[0]), $registered));

        self::assertSame([], $missing, "Named in {$file} but not registered");
    }
}
