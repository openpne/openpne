/**
 * Opening height for the rich editable, from the `rows` its field passes the textarea the other
 * input methods render. A textarea sizes to N line boxes plus its box decoration, so this is `1lh`
 * (the element's own line-height, which follows the md text-base → text-sm switch) times N, plus the
 * `py-2` and 1px border of EDITOR_CONTENT_CLASS (editor-extensions.ts).
 *
 * Kept free of imports: the loading placeholder needs it while the editor's own chunk — tiptap and
 * the Markdown tokenizers — is still lazy.
 */
export function composeEditorRowsMinHeight(rows: number): string {
    return `calc(${rows} * 1lh + 1rem + 2px)`;
}

/** The same height as an inline `style` attribute, for tiptap's `editorProps.attributes`. */
export function composeEditorRowsStyle(rows: number): string {
    return `min-height: ${composeEditorRowsMinHeight(rows)}`;
}
