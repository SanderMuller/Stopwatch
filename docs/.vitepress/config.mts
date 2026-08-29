import { mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { defineConfig } from 'vitepress'
import { link, pages, sections, slug } from './pages'

const site = 'https://sandermuller.github.io/Stopwatch/'

/**
 * A markdown link between source pages (`04-tracking.md#anchor`) points at a file that only exists
 * in the repo. Rewritten to the published URL so a reader with the plain-text copy can follow it.
 */
const absoluteLinks = (markdown: string): string => markdown.replace(
    /\]\((\d+-[a-z-]+)\.md(#[a-z0-9-]*)?\)/g,
    (_, file: string, anchor = '') => `](${site}${slug(file)}${anchor})`,
)

/**
 * An image outside srcDir (`../rendered-stopwatch.png`) resolves for GitHub and for the HTML build,
 * where Vite emits it into assets/ under a content hash. The plain-text copies get neither, so the
 * path is rewritten to the published asset — matched by basename, since only Vite knows the hash.
 */
const absoluteAssets = (markdown: string, outDir: string): string => {
    let emitted: string[] = []

    try {
        emitted = readdirSync(join(outDir, 'assets'))
    } catch {
        return markdown
    }

    return markdown.replace(
        /\]\(\.\.\/([\w-]+)\.(\w+)\)/g,
        (whole, name: string, ext: string) => {
            const hashed = emitted.find(file => file.startsWith(`${name}.`) && file.endsWith(`.${ext}`))

            return hashed ? `](${site}assets/${hashed})` : whole
        },
    )
}

const description = 'Lightweight profiler for PHP and Laravel. Add checkpoints, measure closures, track queries, memory and outbound HTTP, and read the result as an HTML card, a toolbar, Server-Timing headers, or a persistent run log.'

export default defineConfig({
    title: 'Stopwatch',
    description,
    base: '/Stopwatch/',
    cleanUrls: true,
    lastUpdated: true,

    sitemap: {
        hostname: site,
    },

    head: [
        ['meta', { property: 'og:type', content: 'website' }],
        ['meta', { property: 'og:title', content: 'Stopwatch for PHP & Laravel' }],
        ['meta', { property: 'og:description', content: description }],
    ],

    /**
     * Three plain-text builds beside the HTML, for readers that are not browsers
     * (https://llmstxt.org). `llms.txt` is the index, `llms-full.txt` is every page in reading
     * order behind one URL, and each page is also written as `<slug>.md` for a reader that wants
     * one page rather than all of them.
     *
     * The index's link list is generated from the same page list as the sidebar, so it cannot
     * drift from the site. Its preamble is read from `docs/llms-intro.md` when that file exists,
     * because what an agent most needs up front is the package's own rules, which no generator
     * would write. Each link uses the page's `agent` description where one is set, falling back to
     * the sidebar `blurb` — an agent picking a page to fetch wants different wording from a reader
     * deciding whether to keep scrolling.
     */
    buildEnd: async ({ outDir, srcDir }) => {
        mkdirSync(outDir, { recursive: true })

        let intro: string
        try {
            intro = readFileSync(join(srcDir, 'llms-intro.md'), 'utf-8').trim()
        } catch {
            intro = ['# Stopwatch', '', `> ${description}`].join('\n')
        }

        const index: string[] = [intro, '', '## Formats', '',
            `- [llms-full.txt](${site}llms-full.txt): every page below, in reading order, in one fetch`,
            `- Any page is also served as markdown at its own URL plus \`.md\` (for example [checkpoints.md](${site}checkpoints.md))`,
            '']

        for (const section of sections) {
            index.push(`## ${section.text}`, '')
            for (const page of section.pages) {
                index.push(`- [${page.text}](${site}${slug(page.file)}): ${page.agent ?? page.blurb}`)
            }
            index.push('')
        }

        writeFileSync(join(outDir, 'llms.txt'), index.join('\n'))

        const parts: string[] = ['# Stopwatch', '', `> ${description} Full documentation, ${pages.length} pages, in reading order. Index: ${site}llms.txt`, '']

        for (const page of pages) {
            const markdown = absoluteAssets(absoluteLinks(readFileSync(join(srcDir, `${page.file}.md`), 'utf-8')), outDir)

            writeFileSync(join(outDir, `${slug(page.file)}.md`), markdown)
            parts.push(`<!-- ${site}${slug(page.file)} -->`, '', markdown.trim(), '')
        }

        writeFileSync(join(outDir, 'llms-full.txt'), parts.join('\n'))
    },

    // README.md is the GitHub-facing folder index; the site's home is home.md.
    // llms-intro.md is the llms.txt preamble, not a page.
    srcExclude: ['README.md', 'llms-intro.md'],

    rewrites: {
        'home.md': 'index.md',
        ...Object.fromEntries(pages.map(page => [`${page.file}.md`, `${slug(page.file)}.md`])),
    },

    markdown: {
        // Markdown links target the NN-prefixed source files so they work on GitHub; strip the
        // prefix at render time to match the rewritten routes.
        config(md) {
            const defaultRender = md.renderer.rules.link_open
                ?? ((tokens, idx, options, _env, self) => self.renderToken(tokens, idx, options))
            md.renderer.rules.link_open = (tokens, idx, options, env, self) => {
                const href = tokens[idx].attrGet('href')
                if (href && /^(\.\/)?\d+-/.test(href)) {
                    tokens[idx].attrSet('href', href.replace(/^(\.\/)?\d+-/, '$1'))
                }
                return defaultRender(tokens, idx, options, env, self)
            }
        },
    },

    themeConfig: {
        nav: [
            { text: 'Guide', link: link('01-why-stopwatch') },
            { text: 'Configuration', link: link('13-configuration') },
            { text: 'Releases', link: 'https://github.com/SanderMuller/Stopwatch/releases' },
            { text: 'Packagist', link: 'https://packagist.org/packages/sandermuller/stopwatch' },
        ],

        sidebar: sections.map(section => ({
            text: section.text,
            items: section.pages.map(page => ({ text: page.text, link: link(page.file) })),
        })),

        socialLinks: [
            { icon: 'github', link: 'https://github.com/SanderMuller/Stopwatch' },
        ],

        editLink: {
            pattern: 'https://github.com/SanderMuller/Stopwatch/edit/main/docs/:path',
            text: 'Edit this page on GitHub',
        },

        search: {
            provider: 'local',
        },

        outline: {
            level: [2, 3],
        },
    },
})
