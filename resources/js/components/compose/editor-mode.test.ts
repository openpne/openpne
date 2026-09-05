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
 * `method` is what the menu shows for the resolved state; a row without one is a record that gets
 * no menu.
 */
const cases: {
    recordFormat: RecordFormat | undefined;
    pref: ComposeEditorPreference;
    mode: EditorMode;
    format: ComposeFormat | undefined;
    method?: InputMethod;
}[] = [
    { recordFormat: 'op3', pref: 'rich', mode: 'raw', format: undefined },
    { recordFormat: 'op3', pref: 'markdown', mode: 'raw', format: undefined },
    { recordFormat: 'op3', pref: 'plain', mode: 'raw', format: undefined },
    { recordFormat: 'plain', pref: 'rich', mode: 'raw', format: 'plain', method: 'plain' },
    { recordFormat: 'plain', pref: 'markdown', mode: 'raw', format: 'plain', method: 'plain' },
    { recordFormat: 'plain', pref: 'plain', mode: 'raw', format: 'plain', method: 'plain' },
    { recordFormat: 'markdown', pref: 'rich', mode: 'rich', format: 'markdown', method: 'rich' },
    { recordFormat: 'markdown', pref: 'markdown', mode: 'raw', format: 'markdown', method: 'markdown' },
    { recordFormat: 'markdown', pref: 'plain', mode: 'raw', format: 'markdown', method: 'markdown' },
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
    for (const method of METHODS) {
        const { mode, format } = applyInputMethod(method);
        assert.equal(inputMethodFor(mode, format), method);
    }
});

test('rich is only ever offered over a markdown body', () => {
    assert.equal(applyInputMethod('rich').format, 'markdown');
    assert.equal(inputMethodFor('rich', 'markdown'), 'rich');
});

test('needsFormatConfirm: only a stored record crossing the format it is on now', () => {
    assert.equal(needsFormatConfirm('plain', 'plain', 'rich'), true);
    assert.equal(needsFormatConfirm('plain', 'plain', 'markdown'), true);
    assert.equal(needsFormatConfirm('plain', 'plain', 'plain'), false);
    assert.equal(needsFormatConfirm('markdown', 'markdown', 'plain'), true);
    assert.equal(needsFormatConfirm('markdown', 'markdown', 'rich'), false);
    assert.equal(needsFormatConfirm('markdown', 'markdown', 'markdown'), false);
    for (const method of METHODS) {
        assert.equal(needsFormatConfirm(undefined, 'plain', method), false);
        assert.equal(needsFormatConfirm(undefined, 'markdown', method), false);
        assert.equal(needsFormatConfirm('op3', 'markdown', method), false);
    }
});

test('needsFormatConfirm: asks once per crossing, not once per departure from the stored format', () => {
    let current: ComposeFormat = 'plain';
    const ask = (method: InputMethod) => {
        const needed = needsFormatConfirm('plain', current, method);
        current = applyInputMethod(method).format;
        return needed;
    };
    assert.equal(ask('markdown'), true, 'plain → Markdown crosses');
    assert.equal(ask('rich'), false, 'Markdown → rich is mode-only');
    assert.equal(ask('plain'), true, 'rich → no formatting crosses back');
    assert.equal(ask('plain'), false, 'and re-picking the current method asks nothing');
});
