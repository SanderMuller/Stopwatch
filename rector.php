<?php declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\CodeQuality\Rector\If_\ExplicitBoolCompareRector;
use Rector\CodingStyle\Rector\Encapsed\EncapsedStringsToSprintfRector;
use Rector\CodingStyle\Rector\If_\NullableCompareToNullRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\PropertyProperty\RemoveNullPropertyInitializationRector;
use Rector\Naming\Rector\ClassMethod\RenameParamToMatchTypeRector;
use Rector\Naming\Rector\ClassMethod\RenameVariableToMatchNewTypeRector;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\Php74\Rector\Ternary\ParenthesizeNestedTernaryRector;

/**
 * @see https://github.com/rectorphp/rector/blob/main/docs/rector_rules_overview.md
 */
return RectorConfig::configure()
    ->withCache('./.cache/rector', FileCacheStorage::class)
    ->withRules([
        ParenthesizeNestedTernaryRector::class,
    ])
    ->withSkip([
        ClosureToArrowFunctionRector::class,
        EncapsedStringsToSprintfRector::class,
        ExplicitBoolCompareRector::class,
        NullableCompareToNullRector::class,
        RemoveNullPropertyInitializationRector::class,
        RenameParamToMatchTypeRector::class,
        RenameVariableToMatchNewTypeRector::class,
    ])
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withParallel(300, 14, 14)
    // here we can define, what prepared sets of rules will be applied
    ->withPreparedSets(
        codingStyle: true,
        privatization: true,
        earlyReturn: true,
    )
    ->withDeadCodeLevel(40) // max 40
    ->withMemoryLimit('3G')
    ->withPhpSets(php83: true)
    ->withTypeCoverageLevel(37); // max 37
