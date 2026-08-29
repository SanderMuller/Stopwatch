import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
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
     * Two plain-text builds beside the HTML, for readers that are not browsers. `llms-full.txt` is
     * every page in reading order behind one URL; each page is also written as `<slug>.md` for a
     * reader that wants one page rather than all of them.
     */
    buildEnd: async ({ outDir, srcDir }) => {
        const parts: string[] = ['# Stopwatch', '', `> Profiler for PHP and Laravel. Full documentation, ${pages.length} pages, in reading order.`, '']

        for (const page of pages) {
            const markdown = absoluteLinks(readFileSync(join(srcDir, `${page.file}.md`), 'utf-8'))

            mkdirSync(outDir, { recursive: true })
            writeFileSync(join(outDir, `${slug(page.file)}.md`), markdown)
            parts.push(`<!-- ${site}${slug(page.file)} -->`, '', markdown.trim(), '')
        }

        writeFileSync(join(outDir, 'llms-full.txt'), parts.join('\n'))
    },

    // README.md is the GitHub-facing folder index; the site's home is home.md.
    srcExclude: ['README.md'],

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
