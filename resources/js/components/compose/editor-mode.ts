/**
 * Compose input-method resolution — pure and DOM-free, so it is unit-testable and shared by both
 * BodyField's mount (which editor opens) and the pages' `format` initializer (what the form submits).
 *
 * One member-facing choice ("input method") maps onto the two internal axes, so the UI never has to
 * expose `mode × format`. The three valid states are exactly:
 *
 * | input method | mode  | format     | saved preference |
 * |--------------|-------|------------|------------------|
 * | 'rich'       | rich  | 'markdown' | rich             |
 * | 'markdown'   | raw   | 'markdown' | markdown         |
 * | 'plain'      | raw   | 'plain'    | plain            |
 *
 * Initial resolution — the record's format wins, the preference only picks the mode within it:
 *
 * | record            | initialEditorMode        | initialComposeFormat                  |
 * |-------------------|--------------------------|---------------------------------------|
 * | 'op3'             | 'raw' (no menu at all)   | undefined (field omitted)             |
 * | 'plain'           | 'raw' ALWAYS             | 'plain'                               |
 * | 'markdown'        | follow pref (rich↔raw)   | 'markdown'                            |
 * | undefined/create  | follow pref              | plain pref → 'plain', else 'markdown' |
 *
 * WHY a plain record always opens raw, regardless of the preference: opening stored plain text in
 * the rich (Markdown) editor would parse it AS Markdown and could silently reinterpret it — a bare
 * `#`, `*`, `_`, or `|` becomes structure. We never do that implicitly. The member can still pick
 * another input method by hand; that is a deliberate "treat this as Markdown from now on" choice,
 * and it is what flips `format`.
 */

/** The member's saved choice, and the input-method menu's selection — the same three values. */
export type ComposeEditorPreference = 'rich' | 'markdown' | 'plain';
export type InputMethod = ComposeEditorPreference;
export type EditorMode = 'rich' | 'raw';
export type RecordFormat = 'plain' | 'markdown' | 'op3';
export type ComposeFormat = 'plain' | 'markdown';

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
export function initialComposeFormat(pref: ComposeEditorPreference, recordFormat?: RecordFormat): ComposeFormat | undefined {
    switch (recordFormat) {
        case 'op3':
            return undefined;
        case 'plain':
            return 'plain';
        case 'markdown':
            return 'markdown';
        case undefined:
            return pref === 'plain' ? 'plain' : 'markdown';
    }
}

/** The menu's selected item for the form's live state — derived, never stored alongside it. */
export function inputMethodFor(mode: EditorMode, format: ComposeFormat): InputMethod {
    return mode === 'rich' ? 'rich' : format;
}

/** The (mode, format) a menu selection puts the form into. Inverse of {@link inputMethodFor}. */
export function applyInputMethod(method: InputMethod): { mode: EditorMode; format: ComposeFormat } {
    switch (method) {
        case 'rich':
            return { mode: 'rich', format: 'markdown' };
        case 'markdown':
            return { mode: 'raw', format: 'markdown' };
        case 'plain':
            return { mode: 'raw', format: 'plain' };
    }
}

/**
 * Whether picking `method` needs a confirmation first: only on a stored record, and only when it
 * moves the body away from the format it is saved as — that changes how the same bytes render
 * (`#`/`*` become structure, or stop being it). Returning to the record's own format is a no-op for
 * rendering, and an unsaved draft is freely reversible until submit, so neither prompts.
 */
export function needsFormatConfirm(recordFormat: RecordFormat | undefined, method: InputMethod): boolean {
    if (recordFormat !== 'plain' && recordFormat !== 'markdown') {
        return false;
    }
    return applyInputMethod(method).format !== recordFormat;
}
