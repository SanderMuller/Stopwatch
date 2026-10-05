<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use SanderMuller\Stopwatch\PHPStan\NoProbeCallsRule;

/**
 * @extends RuleTestCase<NoProbeCallsRule>
 */
final class NoProbeCallsRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoProbeCallsRule();
    }

    public function test_reports_probe_calls_on_stopwatch_only(): void
    {
        $this->analyse([__DIR__ . '/data/probe-calls.php'], [
            ['Remove temporary stopwatch probe() call before committing.', 14],
            ['Remove temporary stopwatch probe() call before committing.', 15],
            ['Remove temporary stopwatch probe() call before committing.', 18],
            ['Remove temporary stopwatch probe() call before committing.', 19],
        ]);
    }

    public function test_errors_carry_the_ignorable_identifier(): void
    {
        $errors = $this->gatherAnalyserErrors([__DIR__ . '/data/probe-calls.php']);

        self::assertNotEmpty($errors);
        self::assertSame(['stopwatch.probe'], array_values(array_unique(array_map(static fn ($error): ?string => $error->getIdentifier(), $errors))));
    }

    public function test_the_shipped_neon_registers_the_rule(): void
    {
        $neon = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/phpstan/no-probes.neon');

        self::assertStringContainsString('- ' . NoProbeCallsRule::class, $neon);
    }
}
