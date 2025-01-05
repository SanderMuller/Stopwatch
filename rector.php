<?php declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\PropertyProperty\RemoveNullPropertyInitializationRector;
use Rector\DeadCode\Rector\Stmt\RemoveUnreachableStatementRector;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\Php74\Rector\Ternary\ParenthesizeNestedTernaryRector;
use Rector\Php84\Rector\Param\ExplicitNullableParamTypeRector;
use Rector\Privatization\Rector\Property\PrivatizeFinalClassPropertyRector;

/**
 * @see https://github.com/rectorphp/rector/blob/main/docs/rector_rules_overview.md
 */
return RectorConfig::configure()
    ->withCache('./.cache/rector', FileCacheStorage::class)
    ->withRules([
        ExplicitNullableParamTypeRector::class,
        ParenthesizeNestedTernaryRector::class,
        RemoveUnreachableStatementRector::class,
        PrivatizeFinalClassPropertyRector::class,
    ])
    ->withSkip([
        ClosureToArrowFunctionRector::class,
        RemoveNullPropertyInitializationRector::class,
    ])
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withParallel(300, 15, 15)
    // here we can define, what prepared sets of rules will be applied
    ->withPreparedSets(
        codeQuality: false,
        codingStyle: false,
        privatization: false,
        naming: false,
        earlyReturn: false,
    )
    ->withPreparedSets(
        deadCode: true,
        codeQuality: false,
        codingStyle: false,
        typeDeclarations: true,
        privatization: true,
        naming: false,
        instanceOf: true,
        earlyReturn: true,
        strictBooleans: false,
        carbon: true,
        rectorPreset: true,
        phpunitCodeQuality: true,
    )
    ->withMemoryLimit('3G')
    ->withPhpSets(php83: true);
