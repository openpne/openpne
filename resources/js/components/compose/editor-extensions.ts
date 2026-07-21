/**
 * Rich-text editor schema — the single source of truth shared by the React component
 * (rich-text-editor.tsx, through useEditor) and the headless round-trip tests. Kept free of
 * `@/` alias imports so `node --test` can load it directly.
 *
 * INVARIANT: the editor schema equals the server Markdown sanitizer allowlist
 * (app/Support/MarkdownText.php). Modern-surface compose forms submit Markdown and the server
 * renders it (league/commonmark html_input=escape + symfony/html-sanitizer). Authoring a construct
 * the sanitizer would strip means silently losing the user's content on save, so every extension
 * here maps to an allowlisted element:
 *   p br strong em del code pre blockquote ul ol li h1–h6 hr table thead tbody tr th td
 *   a[href] (http/https)
 * Underline — and any other HTML-representable but not-Markdown mark — is excluded: it has no
 * Markdown form and no matching allowlist element.
 *
 * Two behaviours @tiptap/markdown does not give us out of the box, both reproduced from the server
 * so the round-trip matches (see round-trip.test.ts):
 *   (a) Raw HTML in the source stays literal, never re-parsed as formatting. @tiptap/markdown feeds
 *       raw-HTML tokens through each extension's parseHTML (via generateJSON) whenever
 *       window.DOMParser exists, so in the browser `<strong>x</strong>` would become bold while the
 *       server escapes it to literal text. markdownParser() neutralises marked's inline `tag` and
 *       block `html` tokenizers so raw HTML never becomes an HTML token — it lexes as text and
 *       serialises back as escaped entities the server renders identically.
 *   (b) `![alt](src)` survives. With no Image extension the image token collapses to its alt text.
 *       MarkdownImage keeps the source: an inert inline chip in the editor (the server allowlist has
 *       no <img>, so it never displays), `![alt](src)` on serialize.
 */
import { Node } from '@tiptap/core';
import type { Editor, EditorOptions, Extensions, MarkdownToken } from '@tiptap/core';
import { Markdown } from '@tiptap/markdown';
import type { MarkdownExtensionOptions } from '@tiptap/markdown';
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table';
import StarterKit from '@tiptap/starter-kit';
import { Marked } from 'marked';

/** Textarea chrome (mirrors components/ui/textarea.tsx) applied to the ProseMirror editable. */
const EDITOR_CONTENT_CLASS =
    'rich-body flex min-h-24 w-full rounded-field border border-field-border bg-field px-3 py-2 text-base text-foreground shadow-sm transition-colors focus-visible:border-ring focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring aria-[invalid=true]:border-destructive aria-[invalid=true]:ring-2 aria-[invalid=true]:ring-destructive/30 md:text-sm';

/** True only for an http/https URL — the sanitizer's link-scheme allowlist, enforced at authoring. */
function isHttpUrl(url: string): boolean {
    return /^https?:\/\//i.test(url);
}

/**
 * A marked instance that never emits raw-HTML tokens — see invariant (a). Autolinks
 * (`<https://x>`), code spans, and tables are untouched; only inline tags and block HTML are
 * neutralised into literal text.
 */
function markdownParser(): NonNullable<MarkdownExtensionOptions['marked']> {
    const parser = new Marked();
    parser.use({
        tokenizer: {
            html() {
                return undefined;
            },
            tag() {
                return undefined;
            },
        },
    });
    // A private Marked instance is duck-compatible with how the manager uses `marked`
    // (lexer / setOptions / defaults); the option type additionally demands the module's static
    // surface the manager never touches.
    return parser as unknown as NonNullable<MarkdownExtensionOptions['marked']>;
}

/**
 * Inline node that preserves a Markdown image — see invariant (b). Rendered as an inert chip
 * (`.md-image-chip`) showing the alt text; serialises back to `![alt](src)`.
 */
const MarkdownImage = Node.create({
    name: 'markdownImage',
    group: 'inline',
    inline: true,
    atom: true,

    addAttributes() {
        return {
            src: { default: '' },
            alt: { default: '' },
            title: { default: null },
        };
    },

    renderHTML({ node }) {
        const label = (node.attrs.alt as string) || (node.attrs.src as string);
        return ['span', { class: 'md-image-chip', title: node.attrs.src as string }, label];
    },

    markdownTokenName: 'image',

    parseMarkdown(token: MarkdownToken) {
        return {
            type: 'markdownImage',
            attrs: { src: token.href ?? '', alt: token.text ?? '', title: token.title ?? null },
        };
    },

    renderMarkdown(node) {
        const alt = (node.attrs?.alt as string) ?? '';
        const src = (node.attrs?.src as string) ?? '';
        const title = node.attrs?.title ? ` "${node.attrs.title as string}"` : '';
        return `![${alt}](${src}${title})`;
    },
});

/**
 * The editor schema, capped to the server sanitizer allowlist. StarterKit already bundles Link and
 * Underline; underline is dropped, link restricted to http/https.
 */
export function composeExtensions(): Extensions {
    return [
        StarterKit.configure({
            underline: false,
            link: {
                openOnClick: false,
                autolink: true,
                defaultProtocol: 'https',
                protocols: ['http', 'https'],
                // Authoring guard: setLink and autolink only ever create http/https links, matching
                // the sanitizer. A foreign scheme already in the Markdown is preserved on parse; the
                // server drops it on render.
                isAllowedUri: (url, ctx) => isHttpUrl(url) && ctx.defaultValidate(url),
                shouldAutoLink: (url) => isHttpUrl(url),
            },
            // A trailing filler paragraph serialises as a stray blank line, breaking the round-trip;
            // the compose field has no use for the click-below affordance.
            trailingNode: false,
        }),
        Table.configure({ resizable: false }),
        TableRow,
        // One block per cell keeps every cell Markdown-serialisable (a table row is a single line).
        TableHeader.extend({ content: 'paragraph' }),
        TableCell.extend({ content: 'paragraph' }),
        MarkdownImage,
        Markdown.configure({
            marked: markdownParser(),
            // gfm = autolink + strikethrough + table (the server's GFM subset); breaks = single
            // newline → <br>, matching the server soft_break = "<br>".
            markedOptions: { gfm: true, breaks: true },
        }),
    ];
}

/** Parse Markdown into the editor without emitting an update (isolates the early-release API). */
export function parseMarkdown(editor: Editor, md: string): void {
    editor.commands.setContent(md, { contentType: 'markdown', emitUpdate: false });
}

/** Serialize the editor document back to Markdown (isolates the early-release API). */
export function serializeMarkdown(editor: Editor): string {
    return editor.getMarkdown();
}

/**
 * The options factory used by BOTH the React component (via useEditor) and the headless tests (via
 * `new Editor`), so the dirty-signal contract is tested against the production config. Initial
 * content is parsed as Markdown at construction without emitting an update; onUpdate ignores
 * selection-only transactions and otherwise reports canonical Markdown.
 */
export function createComposeEditorOptions(opts: {
    initialMarkdown: string;
    onChange: (md: string) => void;
    attributes?: Record<string, string>;
}): Partial<EditorOptions> {
    const { initialMarkdown, onChange, attributes } = opts;

    return {
        extensions: composeExtensions(),
        content: initialMarkdown,
        contentType: 'markdown',
        editorProps: {
            attributes: {
                class: EDITOR_CONTENT_CLASS,
                ...attributes,
            },
        },
        onUpdate: ({ editor, transaction }) => {
            // docChanged is the dirty signal for the host form; a bare selection move is not an edit.
            if (!transaction.docChanged) {
                return;
            }
            onChange(serializeMarkdown(editor));
        },
    };
}
