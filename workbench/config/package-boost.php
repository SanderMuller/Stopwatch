<?php declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Selected Agents
    |--------------------------------------------------------------------------
    |
    | Which AI agents to sync skills and guidelines for. `null` syncs all
    | supported agents (zero-config dogfooding). Provide an array of agent
    | names to limit the targets — see `package-boost:install` for an
    | interactive picker that writes this key.
    |
    | Supported names: claude_code, cursor, copilot, codex, gemini, junie,
    | kiro, opencode, amp.
    |
    | When `claude_code` is excluded, MCP sync (`.mcp.json`) is skipped —
    | per-agent MCP serializers are not yet implemented.
    |
    */

    'agents' => ['claude_code', 'copilot', 'codex'],
    /*
    |--------------------------------------------------------------------------
    | Vendor Package Discovery
    |--------------------------------------------------------------------------
    |
    | When enabled, `package-boost:sync` scans
    | `vendor/<vendor>/<name>/resources/boost/{skills,guidelines}` for
    | contributions from installed packages and merges them between the
    | shipped defaults and the host's `.ai/` content. Host `.ai/` always
    | wins over vendor contributions on skill-name collisions.
    |
    | Exclude specific packages (by `vendor/name`) from discovery if they
    | ship Boost-only content that doesn't apply to packages.
    |
    */

    'discover_vendor_packages' => true,

    'excluded_vendor_packages' => [
        'sandermuller/package-boost',
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Laravel Boost Guidelines
    |--------------------------------------------------------------------------
    |
    | Keys listed here are merged into `boost.guidelines.exclude`, so Laravel
    | Boost skips them when composing AGENTS.md / CLAUDE.md. The defaults
    | strip guidelines that target application development (Inertia,
    | Livewire, Filament, deployments, etc.) and have no bearing on
    | package development with Testbench.
    |
    | Keys are matched exactly against the keys produced by Boost's
    | GuidelineComposer (e.g. `laravel/core`, `livewire/v3`, `herd`).
    |
    */

    'excluded_boost_guidelines' => [
        'foundation',
        'deployments',
        'herd',
        'sail',
        'laravel/style',
        'laravel/api',
        'laravel/localization',
        'inertia-laravel/core',
        'inertia-react/core',
        'inertia-react/v1',
        'inertia-react/v2',
        'inertia-svelte/core',
        'inertia-svelte/v1',
        'inertia-svelte/v2',
        'inertia-vue/core',
        'inertia-vue/v1',
        'inertia-vue/v2',
        'livewire/core',
        'livewire/v2',
        'livewire/v3',
        'livewire/v4',
        'volt/core',
        'volt/v1',
        'fluxui-free/core',
        'fluxui-pro/core',
        'folio/core',
        'folio/v1',
        'pennant/core',
        'pennant/v1',
        'wayfinder/core',
        'filament/core',
        'filament/v3',
        'filament/v4',
        'nightwatch/core',
        'pulse/core',
        'pulse/v1',
        'tailwindcss/core',
        'tailwindcss/v3',
        'tailwindcss/v4',
        'vite/core',
    ],

];
