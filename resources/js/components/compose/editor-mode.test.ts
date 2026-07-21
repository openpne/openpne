import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    initialComposeFormat,
    initialEditorMode,
    type ComposeEditorPreference,
    type EditorMode,
    type RecordFormat,
} from './editor-mode.ts';

/**
 * The full resolution matrix: every record state (op3 / plain / markdown / create) against both
 * preferences, for both functions. Pure logic — no DOM.
 */
const cases: {
    recordFormat: RecordFormat | undefined;
    pref: ComposeEditorPreference;
    mode: EditorMode;
    format: 'plain' | 'markdown' | undefined;
}[] = [
    // op3: no editor, field omitted so the server preserves the stored format — regardless of pref.
    { recordFormat: 'op3', pref: 'rich', mode: 'raw', format: undefined },
    { recordFormat: 'op3', pref: 'markdown', mode: 'raw', format: undefined },
    // plain: always raw (never reinterpret stored plain text as Markdown), format stays plain.
    { recordFormat: 'plain', pref: 'rich', mode: 'raw', format: 'plain' },
    { recordFormat: 'plain', pref: 'markdown', mode: 'raw', format: 'plain' },
    // markdown: follow the preference; the body is markdown either way.
    { recordFormat: 'markdown', pref: 'rich', mode: 'rich', format: 'markdown' },
    { recordFormat: 'markdown', pref: 'markdown', mode: 'raw', format: 'markdown' },
    // create (undefined): follow the preference; rich opens on a markdown body, raw on a plain one.
    { recordFormat: undefined, pref: 'rich', mode: 'rich', format: 'markdown' },
    { recordFormat: undefined, pref: 'markdown', mode: 'raw', format: 'plain' },
];

for (const { recordFormat, pref, mode, format } of cases) {
    const label = `${recordFormat ?? 'create'} + ${pref}`;

    test(`initialEditorMode: ${label} → ${mode}`, () => {
        assert.equal(initialEditorMode(pref, recordFormat), mode);
    });

    test(`initialComposeFormat: ${label} → ${String(format)}`, () => {
        assert.equal(initialComposeFormat(pref, recordFormat), format);
    });
}
