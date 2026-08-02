// Register the DOM before building an editor.
import './test-dom.ts';

import assert from 'node:assert/strict';
import { test } from 'node:test';
import { Editor } from '@tiptap/core';
import { createComposeEditorOptions, serializeMarkdown } from './editor-extensions.ts';

/**
 * The dirty-signal contract, exercised against the production options factory: onChange is the host
 * form's "field is dirty" signal, so it must fire only on a real document edit — never from parsing
 * the initial content (even when the parse normalizes it) and never from a bare cursor move.
 */

function build(initialMarkdown: string, onChange: (md: string) => void): Editor {
    return new Editor({
        element: document.createElement('div'),
        ...createComposeEditorOptions({ initialMarkdown, onChange }),
    });
}

test('construction with a normalizing fixture fires onChange 0 times', () => {
    let count = 0;
    // Loose list + CRLF: the parse normalizes this (→ a tight list, LF), yet setting the initial
    // content must not look like a user edit.
    const editor = build('- a\r\n\r\n- b\r\n', () => {
        count += 1;
    });
    assert.equal(count, 0);
    editor.destroy();
});

test('a selection-only transaction fires onChange 0 times', () => {
    let count = 0;
    const editor = build('hello world', () => {
        count += 1;
    });
    editor.commands.setTextSelection(3);
    assert.equal(count, 0);
    editor.destroy();
});

test('holding the selection paints it without dirtying the document', () => {
    let count = 0;
    const editor = build('hello world', () => {
        count += 1;
    });
    editor.commands.setTextSelection({ from: 1, to: 6 });
    editor.commands.holdSelection(true);
    assert.match(editor.view.dom.innerHTML, /held-selection/);
    assert.equal(count, 0);
    assert.equal(serializeMarkdown(editor).trim(), 'hello world');
    editor.destroy();
});

test('a blur nobody asked to hold paints nothing', () => {
    // Chromium keeps a contenteditable's selection painted when focus moves to a button and drops it
    // for a text field; painting on every blur would override that, so the hold is explicit.
    const editor = build('hello world', () => {});
    editor.commands.setTextSelection({ from: 1, to: 6 });
    editor.view.dom.dispatchEvent(new FocusEvent('blur'));
    assert.doesNotMatch(editor.view.dom.innerHTML, /held-selection/);
    editor.destroy();
});

test('a real edit fires onChange exactly once with canonical markdown', () => {
    const changes: string[] = [];
    const editor = build('', (md) => {
        changes.push(md);
    });
    editor.chain().insertContent('hello').run();
    assert.equal(changes.length, 1);
    assert.equal(changes[0], 'hello');
    editor.destroy();
});
