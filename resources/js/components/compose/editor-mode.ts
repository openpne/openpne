/**
 * Compose input-method resolution, pure and DOM-free: the record's format wins and the preference
 * only picks the editor within it (docs/internals/body-text.md, "Authoring: the input method").
 */

export type ComposeEditorPreference = 'rich' | 'markdown' | 'plain';
export type InputMethod = ComposeEditorPreference;
export type EditorMode = 'rich' | 'raw';
export type RecordFormat = 'plain' | 'markdown' | 'op3';
export type ComposeFormat = 'plain' | 'markdown';

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

/** undefined ⇔ op3: the page omits the field, so the stored format is preserved. */
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

/** Inverse of {@link inputMethodFor}. */
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
 * `current`, not the stored format, is the axis: comparing against the stored one would re-ask on a
 * mode-only move that already crossed once, and stay silent on a real crossing that lands back on it.
 */
export function needsFormatConfirm(recordFormat: RecordFormat | undefined, current: ComposeFormat, method: InputMethod): boolean {
    if (recordFormat !== 'plain' && recordFormat !== 'markdown') {
        return false;
    }
    return applyInputMethod(method).format !== current;
}
