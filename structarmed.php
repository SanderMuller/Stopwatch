<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;
use Boundwize\StructArmed\Preset\Presets\Psr4Preset;

return Architecture::define()
    ->skip([
        Psr4Preset::CLASSES_MUST_MATCH_COMPOSER => [
            __DIR__ . '/tests/RunLog',
            __DIR__ . '/tests/PHPStan/data',
        ],
    ])
    ->withPresets(Preset::PSR4(), Preset::CODEQUALITY())
    ->layer('Clock', ['src/Clock.php', 'src/SystemClock.php', 'src/FakeClock.php'])
    ->layer('Stopwatch', [
        'src/Stopwatch.php',
        'src/StopwatchCheckpoint.php',
        'src/StopwatchCheckpointCollection.php',
        'src/StopwatchOutput.php',
    ])
    ->layer('HtmlRenderer', [
        'src/StopwatchCheckpointHtmlRenderer.php',
        'src/StopwatchExpansionRenderer.php',
        'src/StopwatchHtmlRenderer.php',
        'src/StopwatchHttpRenderer.php',
        'src/StopwatchIcons.php',
        'src/StopwatchLocationRenderer.php',
        'src/StopwatchQueryRenderer.php',
        'src/StopwatchSlowStyling.php',
    ])
    ->layer('Inject', [
        'src/InjectEnvironmentGate.php',
        'src/InjectMiddlewareRegistrar.php',
        'src/InjectSettings.php',
        'src/ProfileViaStopwatch.php',
        'src/StopwatchInjectAlias.php',
        'src/StopwatchInjectMiddleware.php',
        'src/StopwatchInjector.php',
        'src/StopwatchMiddleware.php',
        'src/StopwatchToolbarRenderer.php',
    ])
    ->layer('Notifications', 'src/Notifications')
    // The run-log hooks the core talks to: two listener interfaces, the end-reason enum and the path helper.
    ->layer('RunLogContracts', [
        'src/RunLog/CheckpointListener.php',
        'src/RunLog/PathRelativiser.php',
        'src/RunLog/RunEndReason.php',
        'src/RunLog/RunRecorder.php',
    ])
    ->layer('RunLog', 'src/RunLog', [
        'src/RunLog/CheckpointListener.php',
        'src/RunLog/PathRelativiser.php',
        'src/RunLog/RunEndReason.php',
        'src/RunLog/RunRecorder.php',
    ])
    ->layer('Console', 'src/Console')
    ->layer('McpTools', 'src/Mcp/Tools')
    ->layer('Mcp', 'src/Mcp', 'src/Mcp/Tools')
    ->layer('Integrations', 'src/Integrations')
    ->layer('PHPStan', 'src/PHPStan')
    ->layer('ServiceProvider', 'src/ServiceProvider.php')
    ->ruleset([
        'Clock' => [],
        // The core renders through HtmlRenderer, notifies the channels and reports to the run-log
        // hooks. Each of those reads the stopwatch back, so these three pairs are mutual by design.
        'Stopwatch' => ['Clock', 'HtmlRenderer', 'Notifications', 'RunLogContracts'],
        'HtmlRenderer' => ['Stopwatch'],
        'Notifications' => ['Stopwatch'],
        'RunLogContracts' => ['Stopwatch'],
        'RunLog' => ['+RunLogContracts'],
        'Inject' => ['+HtmlRenderer'],
        'Integrations' => ['Stopwatch'],
        'PHPStan' => ['Stopwatch'],
        'Console' => ['+RunLog'],
        // The list tool reuses the console's run-list filter parsing.
        'McpTools' => ['+Console'],
        'Mcp' => ['+McpTools'],
        'ServiceProvider' => ['+Console', '+Mcp', '+Inject', '+Integrations', '+Notifications'],
    ]);
