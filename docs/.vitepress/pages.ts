/**
 * Single source of truth for the documentation order.
 *
 * Filenames keep their NN- prefix so GitHub renders docs/ in reading order;
 * `slug` strips the prefix so site URLs stay stable when pages are reordered.
 */
export type DocPage = {
    file: string
    text: string
    /** Sidebar and "next page" wording: a reason for a reader to keep going. */
    blurb: string
    /** llms.txt wording, when an agent choosing a page needs something more specific than the blurb. */
    agent?: string
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
            {
                file: '03-getting-started',
                text: 'Getting started',
                blurb: 'Two checkpoints on a slow request, and the line that owns the time.',
            },
        ],
    },
    {
        text: 'Measuring',
        pages: [
            {
                file: '04-checkpoints',
                text: 'Checkpoints',
                blurb: 'Mark the path, measure a closure, and choose where each checkpoint is emitted.',
            },
            {
                file: '05-tracking',
                text: 'Query, memory and HTTP tracking',
                blurb: 'Attach database, memory and outbound-HTTP metrics to every checkpoint.',
            },
        ],
    },
    {
        text: 'Reading the profile',
        pages: [
            {
                file: '06-html-report',
                text: 'HTML report',
                blurb: 'The self-contained card: severity tiers, per-row detail modals, dark mode, print.',
            },
            {
                file: '07-profiler-toolbar',
                text: 'Profiler toolbar',
                blurb: 'A Debugbar-style toolbar on eligible HTML responses, off outside allowed environments.',
            },
            {
                file: '08-server-timing',
                text: 'Server-Timing and Debugbar',
                blurb: 'Read timings in DevTools, or as a Debugbar timeline tab.',
            },
        ],
    },
    {
        text: 'Run log',
        pages: [
            {
                file: '09-run-log',
                text: 'Persistent run log',
                blurb: 'Keep finished runs on disk so a slow request can be read after the fact.',
            },
            {
                file: '10-crash-diagnostics',
                text: 'Crash diagnostics',
                blurb: 'Capture the exception behind a failed run and pivot to laravel.log.',
            },
            {
                file: '11-debugging',
                text: 'Debugging with probes',
                blurb: 'Put probe() calls on a suspect path, run it once, and read the values back in order.',
                agent: 'Debug mode (STOPWATCH_DEBUG=true): probe() calls written to a per-run JSONL stream, read with stopwatch:runs:show latest, compared with stopwatch:runs:diff, and removed with an exact grep or the opt-in PHPStan rule.',
            },
        ],
    },
    {
        text: 'More',
        pages: [
            {
                file: '12-notifications',
                text: 'Slow-run notifications',
                blurb: 'Dispatch a notification when a run crosses a duration threshold.',
            },
            {
                file: '13-ai-assistant',
                text: 'AI assistant integration',
                blurb: 'Let an agent drive the run log instead of running the commands yourself.',
            },
            {
                file: '14-standalone',
                text: 'Standalone PHP',
                blurb: 'Use the profiler outside Laravel, and what needs the container.',
            },
        ],
    },
    {
        text: 'Reference',
        pages: [
            {
                file: '15-configuration',
                text: 'Configuration reference',
                blurb: 'Every env var and config key, grouped by the feature that owns it.',
            },
            {
                file: '16-api',
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
