/**
 * Single source of truth for the documentation order.
 *
 * Filenames keep their NN- prefix so GitHub renders docs/ in reading order;
 * `slug` strips the prefix so site URLs stay stable when pages are reordered.
 */
export type DocPage = {
    file: string
    text: string
    blurb: string
}

export type DocSection = {
    text: string
    pages: DocPage[]
}

export const sections: DocSection[] = [
    {
        text: 'Getting started',
        pages: [
            {
                file: '01-why-stopwatch',
                text: 'Why Stopwatch?',
                blurb: 'What it answers that a log line does not, and when an APM is the better tool.',
            },
            {
                file: '02-installation',
                text: 'Installation',
                blurb: 'Require the package, publish the config, and read your first profile.',
            },
        ],
    },
    {
        text: 'Measuring',
        pages: [
            {
                file: '03-checkpoints',
                text: 'Checkpoints',
                blurb: 'Mark the path, measure a closure, and choose where each checkpoint is emitted.',
            },
            {
                file: '04-tracking',
                text: 'Query, memory and HTTP tracking',
                blurb: 'Attach database, memory and outbound-HTTP metrics to every checkpoint.',
            },
        ],
    },
    {
        text: 'Reading the profile',
        pages: [
            {
                file: '05-html-report',
                text: 'HTML report',
                blurb: 'The self-contained card: severity tiers, per-row detail modals, dark mode, print.',
            },
            {
                file: '06-profiler-toolbar',
                text: 'Profiler toolbar',
                blurb: 'A Debugbar-style toolbar on eligible HTML responses, off outside allowed environments.',
            },
            {
                file: '07-server-timing',
                text: 'Server-Timing and Debugbar',
                blurb: 'Read timings in DevTools, or as a Debugbar timeline tab.',
            },
        ],
    },
    {
        text: 'Run log',
        pages: [
            {
                file: '08-run-log',
                text: 'Persistent run log',
                blurb: 'Keep finished runs on disk so a slow request can be read after the fact.',
            },
            {
                file: '09-crash-diagnostics',
                text: 'Crash diagnostics',
                blurb: 'Capture the exception behind a failed run and pivot to laravel.log.',
            },
        ],
    },
    {
        text: 'More',
        pages: [
            {
                file: '10-notifications',
                text: 'Slow-run notifications',
                blurb: 'Dispatch a notification when a run crosses a duration threshold.',
            },
            {
                file: '11-ai-assistant',
                text: 'AI assistant integration',
                blurb: 'Let an agent drive the run log instead of running the commands yourself.',
            },
            {
                file: '12-standalone',
                text: 'Standalone PHP',
                blurb: 'Use the profiler outside Laravel, and what needs the container.',
            },
        ],
    },
    {
        text: 'Reference',
        pages: [
            {
                file: '13-configuration',
                text: 'Configuration reference',
                blurb: 'Every env var and config key, grouped by the feature that owns it.',
            },
            {
                file: '14-api',
                text: 'API reference',
                blurb: 'Lifecycle, serialization, and the runtime enable/disable switch.',
            },
        ],
    },
]

/** Flat reading order — drives rewrites and the sidebar. */
export const pages: DocPage[] = sections.flatMap(section => section.pages)

export const slug = (file: string) => file.replace(/^\d+-/, '')

export const link = (file: string) => `/${slug(file)}`
