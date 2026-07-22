import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    applyInputMethod,
    initialComposeFormat,
    initialEditorMode,
    inputMethodFor,
    needsFormatConfirm,
    type ComposeEditorPreference,
    type ComposeFormat,
    type EditorMode,
    type InputMethod,
    type RecordFormat,
} from './editor-mode.ts';

const METHODS: InputMethod[] = ['rich', 'markdown', 'plain'];

/**
 * The full resolution matrix: every record state (op3 / plain / markdown / create) against all three
 * preferences. `method` is what the menu shows for the resolved state — the record's format always
 * wins, the preference only picks the mode within it. Pure logic — no DOM.
 */
const cases: {
    recordFormat: RecordFormat | undefined;
    pref: ComposeEditorPreference;
    mode: EditorMode;
    format: ComposeFormat | undefined;
    method?: InputMethod;
}[] = [
    // op3: no editor choice at all, field omitted so the server preserves the stored format.
    { recordFormat: 'op3', pref: 'rich', mode: 'raw', format: undefined },
    { recordFormat: 'op3', pref: 'markdown', mode: 'raw', format: undefined },
    { recordFormat: 'op3', pref: 'plain', mode: 'raw', format: undefined },
    // plain: always raw (never reinterpret stored plain text as Markdown), format stays plain.
    { recordFormat: 'plain', pref: 'rich', mode: 'raw', format: 'plain', method: 'plain' },
    { recordFormat: 'plain', pref: 'markdown', mode: 'raw', format: 'plain', method: 'plain' },
    { recordFormat: 'plain', pref: 'plain', mode: 'raw', format: 'plain', method: 'plain' },
    // markdown: the body is markdown either way; only rich vs not-rich follows the preference.
    { recordFormat: 'markdown', pref: 'rich', mode: 'rich', format: 'markdown', method: 'rich' },
    { recordFormat: 'markdown', pref: 'markdown', mode: 'raw', format: 'markdown', method: 'markdown' },
    { recordFormat: 'markdown', pref: 'plain', mode: 'raw', format: 'markdown', method: 'markdown' },
    // create (undefined): the preference maps straight onto the input method.
    { recordFormat: undefined, pref: 'rich', mode: 'rich', format: 'markdown', method: 'rich' },
    { recordFormat: undefined, pref: 'markdown', mode: 'raw', format: 'markdown', method: 'markdown' },
    { recordFormat: undefined, pref: 'plain', mode: 'raw', format: 'plain', method: 'plain' },
];

for (const { recordFormat, pref, mode, format, method } of cases) {
    const label = `${recordFormat ?? 'create'} + ${pref}`;

    test(`initialEditorMode: ${label} → ${mode}`, () => {
        assert.equal(initialEditorMode(pref, recordFormat), mode);
    });

    test(`initialComposeFormat: ${label} → ${String(format)}`, () => {
        assert.equal(initialComposeFormat(pref, recordFormat), format);
    });

    if (method !== undefined) {
        test(`opens showing "${method}": ${label}`, () => {
            assert.equal(inputMethodFor(initialEditorMode(pref, recordFormat), format as ComposeFormat), method);
        });
    }
}

test('the three input methods are exactly the three valid (mode, format) states', () => {
    assert.deepEqual(
        METHODS.map(applyInputMethod),
        [
            { mode: 'rich', format: 'markdown' },
            { mode: 'raw', format: 'markdown' },
            { mode: 'raw', format: 'plain' },
        ],
    );
    // Round-trips: a method survives applying + reading back, so the menu can derive its selection
    // from the form state instead of storing a third, desyncable copy of it.
    for (const method of METHODS) {
        const { mode, format } = applyInputMethod(method);
        assert.equal(inputMethodFor(mode, format), method);
    }
});

test('rich is only ever offered over a markdown body', () => {
    assert.equal(applyInputMethod('rich').format, 'markdown');
    assert.equal(inputMethodFor('rich', 'markdown'), 'rich');
});

test('needsFormatConfirm: only a stored record moving away from its own format', () => {
    // Stored plain: switching to either markdown-backed method reinterprets the text.
    assert.equal(needsFormatConfirm('plain', 'rich'), true);
    assert.equal(needsFormatConfirm('plain', 'markdown'), true);
    assert.equal(needsFormatConfirm('plain', 'plain'), false);
    // Stored markdown: dropping to plain stops rendering its symbols.
    assert.equal(needsFormatConfirm('markdown', 'plain'), true);
    // ...but moving between the two markdown-backed methods changes no rendering.
    assert.equal(needsFormatConfirm('markdown', 'rich'), false);
    assert.equal(needsFormatConfirm('markdown', 'markdown'), false);
    // A draft is freely reversible until submit, and op3 has no menu to begin with.
    for (const method of METHODS) {
        assert.equal(needsFormatConfirm(undefined, method), false);
        assert.equal(needsFormatConfirm('op3', method), false);
    }
});
