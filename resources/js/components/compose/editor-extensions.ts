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
 *   (c) Content the schema can't represent stays literal and re-renders identically on the server.
 *       Two more marked constructs would otherwise be silently corrupted on the first edit+save (the
 *       dirty rule only shields an unedited body): a GFM task item (`- [ ] x`) has its marker
 *       stripped, and an escaped pipe in a table cell (`| a \| b |`) collapses the column structure.
 *       markdownParser() keeps the task marker literal; the table serializer re-escapes cell pipes.
 *       And every serializer here (image alt/src/title, cell text) escapes Markdown-significant
 *       characters so nothing breaks structure or emits a raw `<tag` (verified against the server
 *       renderer in round-trip.test.ts).
 */
import { Node } from '@tiptap/core';
import type { Editor, EditorOptions, Extensions, MarkdownRendererHelpers, MarkdownToken } from '@tiptap/core';
import { Markdown } from '@tiptap/markdown';
import type { MarkdownExtensionOptions } from '@tiptap/markdown';
import { renderTableToMarkdown, Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table';
import StarterKit from '@tiptap/starter-kit';
import { Marked, Tokenizer } from 'marked';
import type { Tokens } from 'marked';

/** marked's built-in list tokenizer, wrapped below to neutralise GFM task-item detection. */
const originalListTokenizer = Tokenizer.prototype.list;

/**
 * Textarea chrome (mirrors components/ui/textarea.tsx) applied to the ProseMirror editable —
 * except `block` where the textarea uses `flex`: on a contenteditable div, flex would lay the
 * child block nodes (paragraphs, lists) out in a row, so Enter never visibly breaks the line.
 */
const EDITOR_CONTENT_CLASS =
    'rich-body block min-h-24 w-full rounded-field border border-field-border bg-field px-3 py-2 text-base text-foreground shadow-sm transition-colors focus-visible:border-ring focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring aria-[invalid=true]:border-destructive aria-[invalid=true]:ring-2 aria-[invalid=true]:ring-destructive/30 md:text-sm';

/**
 * Opening height for a `rows` the host passed, so the editable stands as tall as the textarea the
 * other input methods render for the same `rows`. A textarea sizes to N line boxes plus its box
 * decoration, so this is `1lh` (the element's own line-height, which follows the md text-base →
 * text-sm switch) times N plus the py-2 and 1px border of EDITOR_CONTENT_CLASS above. Carried as an
 * inline style because it is per-instance; with no `rows` the class keeps its min-h-24 floor.
 */
export function composeEditorRowsStyle(rows: number): string {
    return `min-height: calc(${rows} * 1lh + 1rem + 2px)`;
}

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
            // Undo GFM task-item detection so the marker survives — invariant (c). The server (no
            // task-list extension) renders `- [ ] x` as the literal `[ ] x`, and the schema has no
            // task node; letting marked strip the marker would lose it on the first save. Done at the
            // tokenizer so a `[ ]` inside a code block (which is not a list item) is left untouched.
            list(src) {
                const token = originalListTokenizer.call(this, src);
                if (token) {
                    for (const item of token.items) {
                        if (!item.task) {
                            continue;
                        }
                        const marker: Tokens.Text = {
                            type: 'text',
                            raw: item.checked ? '[x] ' : '[ ] ',
                            text: item.checked ? '[x] ' : '[ ] ',
                        };
                        item.task = false;
                        item.checked = false;
                        const checkboxIndex = item.tokens.findIndex((child) => child.type === 'checkbox');
                        if (checkboxIndex === -1) {
                            continue;
                        }
                        // Fold the marker into the first text/paragraph block after the checkbox by
                        // mutating its inline `tokens` array IN PLACE — marked's deferred inline pass
                        // holds a reference to that array, so reassigning it would drop the marker. This
                        // keeps a loose item's marker inside its first paragraph instead of emitting it
                        // as a standalone paragraph.
                        const content = item.tokens.find(
                            (child, index) => index > checkboxIndex && (child.type === 'paragraph' || child.type === 'text'),
                        ) as Tokens.Paragraph | Tokens.Text | undefined;
                        if (content && Array.isArray(content.tokens)) {
                            content.tokens.unshift(marker);
                            item.tokens = item.tokens.filter((child) => child.type !== 'checkbox');
                        } else {
                            // No following text (e.g. `- [ ]` alone): keep the marker as a literal sibling.
                            item.tokens = item.tokens.map((child) => (child.type === 'checkbox' ? marker : child));
                        }
                    }
                }
                return token;
            },
        },
    });
    // A private Marked instance is duck-compatible with how the manager uses `marked`
    // (lexer / setOptions / defaults); the option type additionally demands the module's static
    // surface the manager never touches.
    return parser as unknown as NonNullable<MarkdownExtensionOptions['marked']>;
}

// --- Markdown escaping for content the schema keeps as literal text (invariant c) ---

/**
 * Escape an image alt so it can't break out of `![...]` (brackets / backslash), start a code span
 * (backtick), or emit a raw `<tag`. `<`/`>` become entities rather than backslash escapes because
 * the gate-c contract forbids a `<` followed by a letter even when backslash-escaped.
 */
function escapeImageAlt(alt: string): string {
    return alt.replace(/[\\`[\]]/g, '\\$&').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/**
 * Serialize an image destination: backslash-escape the `()` / `\` delimiters and percent-encode the
 * chars that would otherwise force an angle-bracket destination (`<url>` reads as a raw tag under
 * gate c). A clean http(s) URL is returned unchanged.
 */
function serializeImageDestination(src: string): string {
    return src
        .replace(/[\\()]/g, '\\$&')
        .replace(/</g, '%3C')
        .replace(/>/g, '%3E')
        .replace(/ /g, '%20');
}

/** Serialize an image title in double quotes, escaping the quote/backslash and `<`/`>` (gate c). */
function serializeImageTitle(title: string): string {
    return ` "${title.replace(/[\\"]/g, '\\$&').replace(/</g, '&lt;').replace(/>/g, '&gt;')}"`;
}

/**
 * Escape every unescaped `|` in table-cell text so it can't be read as a column separator. A pipe is
 * unescaped only when preceded by an EVEN-length run of backslashes (each pair is one literal `\`); an
 * odd run means the pipe is already escaped. A lookbehind (`(?<!\\)`) can't tell the two apart.
 */
function escapeTableCellText(text: string): string {
    return text.replace(/(\\*)\|/g, (match, backslashes: string) =>
        backslashes.length % 2 === 0 ? `${backslashes}\\|` : match,
    );
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
        const alt = escapeImageAlt((node.attrs?.alt as string) ?? '');
        const src = serializeImageDestination((node.attrs?.src as string) ?? '');
        const title = node.attrs?.title ? serializeImageTitle(node.attrs.title as string) : '';
        return `![${alt}](${src}${title})`;
    },
});

/**
 * Table with a serializer that re-escapes pipes in cell text — invariant (c). The vendor serializer
 * emits cell text verbatim, so a `|` inside a cell (valid input, written `\|`) would be read as a
 * column separator and collapse the table into a paragraph. Wrapping renderChildren re-escapes it.
 */
const ComposeTable = Table.extend({
    renderMarkdown(node, helpers) {
        const escapingHelpers: MarkdownRendererHelpers = {
            ...helpers,
            renderChildren: (nodes, ctx) => escapeTableCellText(helpers.renderChildren(nodes, ctx)),
        };
        return renderTableToMarkdown(node, escapingHelpers);
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
        ComposeTable.configure({ resizable: false }),
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

/**
 * The full editable-element attribute map (chrome class + caller attributes). Exported so the
 * component can push updated aria-* attributes into a live editor via editor.setOptions — the
 * attributes handed to createComposeEditorOptions are only read at construction. The explicit
 * textbox role is what permits aria-label/aria-required on the contenteditable (axe
 * aria-allowed-attr; contenteditable has no implicit role).
 */
export function composeEditorAttributes(attributes?: Record<string, string>): Record<string, string> {
    return { class: EDITOR_CONTENT_CLASS, role: 'textbox', 'aria-multiline': 'true', ...attributes };
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
            attributes: composeEditorAttributes(attributes),
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
