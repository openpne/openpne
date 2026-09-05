/**
 * The editor schema equals the server Markdown sanitizer allowlist (app/Support/MarkdownText.php),
 * so nothing authored here is dropped on save (docs/internals/body-text.md, "Authoring: the input
 * method"). Kept free of `@/` alias imports so the headless tests can load it directly.
 */
import { Extension, Node } from '@tiptap/core';
import type { Editor, EditorOptions, Extensions, MarkdownRendererHelpers, MarkdownToken } from '@tiptap/core';
import { Markdown } from '@tiptap/markdown';
import type { MarkdownExtensionOptions } from '@tiptap/markdown';
import { renderTableToMarkdown, Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import type { Transaction } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';
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
 * True only for an http/https URL — the sanitizer's link-scheme allowlist, enforced at authoring.
 */
function isHttpUrl(url: string): boolean {
    return /^https?:\/\//i.test(url);
}

/**
 * Never emits raw-HTML tokens: wherever `window.DOMParser` exists @tiptap/markdown would feed them
 * through each extension's parseHTML and turn `<strong>x</strong>` into bold, where the server
 * escapes it to literal text.
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
            // The server renders `- [ ] x` literally and the schema has no task node, so letting
            // marked strip the marker would lose it on the first save; done at the tokenizer, a
            // `[ ]` inside a code block is left alone.
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
                            // marked >= 18.0.10 puts a loose item's checkbox inside the first block's
                            // inline tokens rather than beside it, so there is nothing to fold — swap it
                            // for the marker where it sits, in place for the same reason as below.
                            const first = item.tokens[0] as Tokens.Paragraph | Tokens.Text | undefined;
                            const inline = first && Array.isArray(first.tokens) ? first.tokens : undefined;
                            const nested = inline?.findIndex((child) => child.type === 'checkbox') ?? -1;
                            if (inline && nested !== -1) {
                                inline.splice(nested, 1, marker);
                            }
                            continue;
                        }
                        // Mutate the inline `tokens` array IN PLACE: marked's deferred inline pass
                        // holds a reference to it, so reassigning would drop the marker.
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

/**
 * `<`/`>` become entities rather than backslash escapes: serialized output may carry no `<`
 * followed by a letter, escaped or not.
 */
function escapeImageAlt(alt: string): string {
    return alt.replace(/[\\`[\]]/g, '\\$&').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/**
 * Percent-encodes the characters that would otherwise force an angle-bracket destination, which
 * reads as a raw tag.
 */
function serializeImageDestination(src: string): string {
    return src
        .replace(/[\\()]/g, '\\$&')
        .replace(/</g, '%3C')
        .replace(/>/g, '%3E')
        .replace(/ /g, '%20');
}

function serializeImageTitle(title: string): string {
    return ` "${title.replace(/[\\"]/g, '\\$&').replace(/</g, '&lt;').replace(/>/g, '&gt;')}"`;
}

/**
 * A pipe is unescaped only when preceded by an even-length run of backslashes, each pair being one
 * literal `\`. A lookbehind cannot tell that from an escaped backslash standing before a pipe.
 */
function escapeTableCellText(text: string): string {
    return text.replace(/(\\*)\|/g, (match, backslashes: string) =>
        backslashes.length % 2 === 0 ? `${backslashes}\\|` : match,
    );
}

/**
 * Preserves a Markdown image: with no Image extension the token would collapse to its alt text, so
 * this keeps the source and serialises back to `![alt](src)`.
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
 * The vendor serializer emits cell text verbatim, so a `|` inside a cell (valid input, written
 * `\|`) would be read as a column separator and collapse the table into a paragraph.
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

const heldSelectionKey = new PluginKey<boolean>('heldSelection');

/**
 * `blur()` clears the painted range although the selection survives in the editor state, so a UI
 * that blurs the editor on purpose paints the range itself. Held by the caller rather than on blur
 * alone: Chromium keeps the painted selection when focus moves to a button and drops it for a text
 * field.
 */
declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        heldSelection: {
            /** Paint the current selection until focus returns or the caller releases it. */
            holdSelection: (held: boolean) => ReturnType;
        };
    }
}

const HeldSelection = Extension.create({
    name: 'heldSelection',

    addCommands() {
        return {
            holdSelection:
                (held: boolean) =>
                ({ tr, dispatch }: { tr: Transaction; dispatch?: (tr: Transaction) => void }) => {
                    dispatch?.(tr.setMeta(heldSelectionKey, held));
                    return true;
                },
        };
    },

    addProseMirrorPlugins() {
        return [
            new Plugin<boolean>({
                key: heldSelectionKey,
                state: {
                    init: () => false,
                    apply: (tr, held) => (tr.getMeta(heldSelectionKey) as boolean | undefined) ?? held,
                },
                props: {
                    decorations(state) {
                        if (state.selection.empty || !heldSelectionKey.getState(state)) {
                            return null;
                        }
                        const { from, to } = state.selection;
                        return DecorationSet.create(state.doc, [Decoration.inline(from, to, { class: 'held-selection' })]);
                    },
                    handleDOMEvents: {
                        // Focus returning ends the hold whoever set it; false leaves the event to
                        // ProseMirror's own handlers.
                        focus: (view) => {
                            if (heldSelectionKey.getState(view.state)) {
                                view.dispatch(view.state.tr.setMeta(heldSelectionKey, false));
                            }
                            return false;
                        },
                    },
                },
            }),
        ];
    },
});

/**
 * StarterKit already bundles Link and Underline: underline is dropped, having no Markdown form and
 * no allowlisted element, and links are restricted to http/https. HeldSelection is view-only, a
 * decoration rather than a schema entry.
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
                // setLink and autolink only ever create http/https links; a foreign scheme already
                // in the Markdown survives the parse and is dropped by the server on render.
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
        HeldSelection,
    ];
}

/**
 * Exported so the component can push updated aria-* attributes into a live editor: the attributes
 * handed to createComposeEditorOptions are read only at construction. The explicit textbox role is
 * what permits aria-label/aria-required on a contenteditable, which has no implicit role.
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
 * Shared by the React component and the headless tests, so the dirty-signal contract is exercised
 * against the production config.
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
