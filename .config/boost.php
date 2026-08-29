<?php

declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Enums\Tag;

/**
 * boost-core configuration — which AI agents `vendor/bin/boost sync` writes to,
 * which dependency vendors' shipped skills are synced, and which skill tags
 * are active.
 *
 * `withAllowedVendors()` is an explicit allowlist: a dependency's skills sync
 * ONLY if its package name is listed here. The boost umbrellas + the
 * `sandermuller/boost-skills` skill library are listed below — your package
 * installs whichever umbrella its category uses; any not installed is a
 * harmless no-op. Add other skill-shipping dependency vendors as you adopt them.
 *
 * `withTags()` filters `sandermuller/boost-skills`: with no tags you still get
 * the universal skills; each tag adds its capability-specific set (e.g. `php`
 * adds backend-quality / pre-release, `jira` adds the jira-* skills). `voice`
 * is always on — it ships the writing-voice guideline every repo in this setup
 * uses; keep it in the list. Hand-edit this file to change tags, then run
 * `vendor/bin/boost sync`. `vendor/bin/boost install` also works, but it
 * REWRITES the whole `withTags()` list from the picker (and drops the call when
 * you select nothing) — keep `voice` checked there, or add it back afterwards.
 *
 * Docs: https://github.com/sandermuller/boost-core
 */
return BoostConfig::configure()
    ->withAgents([
        Agent::CLAUDE_CODE,
        Agent::COPILOT,
        Agent::CODEX,
    ])
    ->withAllowedVendors([
        'sandermuller/boost-skills',
        'sandermuller/package-boost-laravel',
        'sandermuller/package-boost-php',
    ])
    ->withTags([
        Tag::Php,
        Tag::Github,
        'release-automation',
        'voice',
    ])
    ->withDisabledEmitters([]);
