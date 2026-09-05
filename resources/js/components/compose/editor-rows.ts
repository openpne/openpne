/**
 * The rich editable opens at the height the `rows` of the textarea the other input methods render:
 * `1lh` times N plus the `py-2` and 1px border of EDITOR_CONTENT_CLASS (editor-extensions.ts). Kept
 * free of imports, so the loading placeholder can use it while the editor's own chunk is still lazy.
 */
export function composeEditorRowsMinHeight(rows: number): string {
    return `calc(${rows} * 1lh + 1rem + 2px)`;
}

export function composeEditorRowsStyle(rows: number): string {
    return `min-height: ${composeEditorRowsMinHeight(rows)}`;
}
