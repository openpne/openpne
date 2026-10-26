import assert from 'node:assert/strict';
import { test } from 'node:test';
import { safeUrl } from './safe-url.ts';

test('an http or https url is kept', () => {
    assert.equal(safeUrl('https://example.org/x?y=1#z'), 'https://example.org/x?y=1#z');
    assert.equal(safeUrl('http://example.org/'), 'http://example.org/');
});

test('a script-running scheme is refused, however it is spelled', () => {
    for (const value of ['javascript:alert(1)', 'JavaScript:alert(1)', ' javascript:alert(1)', 'java\tscript:alert(1)', 'data:text/html,<script>alert(1)</script>', 'vbscript:x']) {
        assert.equal(safeUrl(value), null, value);
    }
});

test('a relative reference is refused, since the page would resolve it', () => {
    for (const value of ['/x', '//example.org/x', 'x', '']) {
        assert.equal(safeUrl(value), null, value);
    }
});

test('a caller names the schemes it takes', () => {
    assert.equal(safeUrl('blob:https://example.org/0b1c', ['blob:']), 'blob:https://example.org/0b1c');
    assert.equal(safeUrl('https://example.org/', ['blob:']), null);
});
