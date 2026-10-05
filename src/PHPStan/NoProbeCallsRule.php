<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;
use SanderMuller\Stopwatch\Stopwatch;

/**
 * Reports `Stopwatch::probe()` calls. Probes are temporary debug instrumentation;
 * include `vendor/sandermuller/stopwatch/resources/phpstan/no-probes.neon` to fail
 * the analysis while one is left in the code.
 *
 * @implements Rule<MethodCall>
 */
final class NoProbeCallsRule implements Rule
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Identifier || $node->name->toLowerString() !== 'probe') {
            return [];
        }

        // A nullable receiver (`?Stopwatch`) must still be reported.
        $type = TypeCombinator::removeNull($scope->getType($node->var));

        if (! (new ObjectType(Stopwatch::class))->isSuperTypeOf($type)->yes()) {
            return [];
        }

        return [
            RuleErrorBuilder::message('Remove temporary stopwatch probe() call before committing.')
                ->identifier('stopwatch.probe')
                ->build(),
        ];
    }
}
