import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { parseFrontmatter } from 'astro/markdown';
import { createMarkdownProcessor } from '@astrojs/markdown-remark';

const root = path.resolve(process.cwd(), '..');
// Only these repository files are published by the documentation build.
const pages = [
    'docs/index.mdx',
    'docs/architecture.mdx',
    'docs/getting-started/installation.mdx',
    'docs/getting-started/configuration.mdx',
    'docs/getting-started/local-development.mdx',
    'docs/guides/core-concepts.mdx',
    'docs/guides/workflows.mdx',
    'docs/guides/development.mdx',
    'docs/guides/directory-structure.mdx',
    'docs/guides/customization.mdx',
    'docs/guides/extensibility.mdx',
    'docs/guides/security.mdx',
    'docs/guides/ci-cd.mdx',
    'docs/guides/landing.mdx',
    'docs/reference/examples.mdx',
    'CONTRIBUTING.md',
    'AGENTS.md',
    'packages/koamishin-auth-js/README.md',
    'packages/koamishin-auth-php/README.md',
];
const publicFiles = [
    'packages/koamishin-auth-js/LICENSE',
    'packages/koamishin-auth-php/LICENSE',
];

function getBase() {
    const base =
        process.env.ASTRO_BASE ||
        (process.env.GITHUB_ACTIONS ? '/koamishin-portal' : '');
    return base.replace(/\/+$/, '');
}

function pageSlug(source) {
    if (source === 'docs/index.mdx') return '';
    if (source.startsWith('docs/')) return source.slice(5, -4);
    return `repository/${source.replace(/\.md$/i, '')}`;
}

function pageUrl(source) {
    const slug = pageSlug(source);
    const base = getBase();
    return `${base}/docs/${slug ? `${slug}/` : ''}`;
}

function rewriteLinks(source, pageSet, fileSet) {
    return () => (tree) => {
        function visit(node) {
            if (
                (node.type === 'link' ||
                    node.type === 'definition' ||
                    node.type === 'image') &&
                node.url &&
                !/^(?:[a-z][a-z\d+.-]*:|\/\/|\/|#)/i.test(node.url)
            ) {
                const match = node.url.match(/^([^?#]*)([?#].*)?$/);
                const target = path.posix.normalize(
                    path.posix.join(
                        path.posix.dirname(source),
                        decodeURIComponent(match[1]),
                    ),
                );
                const suffix = match[2] || '';
                const base = getBase();
                if (pageSet.has(target))
                    node.url = `${pageUrl(target)}${suffix}`;
                else if (fileSet.has(target))
                    node.url = `${base}/docs/files/${target}${suffix}`;
                else
                    throw new Error(
                        `Unmapped documentation link in ${source}: ${node.url}`,
                    );
            }
            if (node.children) node.children.forEach(visit);
        }
        visit(tree);
    };
}

export async function getDocumentation() {
    const sources = pages;
    const pageSet = new Set(sources);
    const fileSet = new Set(publicFiles);
    return Promise.all(
        sources.map(async (source) => {
            const raw = await readFile(path.join(root, source), 'utf8');
            const { frontmatter, content } = parseFrontmatter(raw);
            const processor = await createMarkdownProcessor({
                remarkPlugins: [rewriteLinks(source, pageSet, fileSet)],
            });
            const rendered = await processor.render(content);
            const title =
                frontmatter.title ||
                content.match(/^#\s+(.+)$/m)?.[1] ||
                source;
            return {
                source,
                slug: pageSlug(source),
                url: pageUrl(source),
                title,
                description: frontmatter.description || '',
                html: rendered.code,
                headings: rendered.metadata.headings,
            };
        }),
    );
}

export async function getPublicFiles() {
    return Promise.all(
        publicFiles.map(async (source) => ({
            source,
            content: await readFile(path.join(root, source), 'utf8'),
        })),
    );
}
