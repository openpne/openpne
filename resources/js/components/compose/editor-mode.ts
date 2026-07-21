/**
 * Compose-editor mode resolution — pure and DOM-free, so it is unit-testable and shared by both
 * BodyField's mount (which editor opens) and the pages' `format` initializer (what the form submits).
 *
 * | record            | initialEditorMode        | initialComposeFormat            |
 * |-------------------|--------------------------|---------------------------------|
 * | 'op3'             | 'raw' (no switch UI)     | undefined (field omitted)       |
 * | 'plain'           | 'raw' ALWAYS             | 'plain'                         |
 * | 'markdown'        | follow pref (rich↔raw)   | 'markdown'                      |
 * | undefined/create  | follow pref              | rich → 'markdown', else 'plain' |
 *
 * WHY a plain record always opens raw, regardless of the preference: opening stored plain text in
 * the rich (Markdown) editor would parse it AS Markdown and could silently reinterpret it — a bare
 * `#`, `*`, `_`, or `|` becomes structure. We never do that implicitly. The member can still switch
 * to rich by hand; that is a deliberate "treat this as Markdown from now on" choice, and the switch
 * is what flips `format` to markdown.
 */

export type ComposeEditorPreference = 'rich' | 'markdown';
export type EditorMode = 'rich' | 'raw';
export type RecordFormat = 'plain' | 'markdown' | 'op3';

/** Which editor a compose form opens with. Never writes the preference — a mount is not a choice. */
export function initialEditorMode(pref: ComposeEditorPreference, recordFormat?: RecordFormat): EditorMode {
    switch (recordFormat) {
        case 'op3':
        case 'plain':
            return 'raw';
        case 'markdown':
        case undefined:
            return pref === 'rich' ? 'rich' : 'raw';
    }
}

/** The `format` a compose form starts with. undefined ⇔ op3 (the page omits the field to preserve it). */
export function initialComposeFormat(
    pref: ComposeEditorPreference,
    recordFormat?: RecordFormat,
): 'plain' | 'markdown' | undefined {
    switch (recordFormat) {
        case 'op3':
            return undefined;
        case 'plain':
            return 'plain';
        case 'markdown':
            return 'markdown';
        case undefined:
            return pref === 'rich' ? 'markdown' : 'plain';
    }
}
