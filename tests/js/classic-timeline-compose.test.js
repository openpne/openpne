import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { runInThisContext } from 'node:vm';

const load = (path) => {
    const source = readFileSync(fileURLToPath(new URL(`../../${path}`, import.meta.url)), 'utf8');
    const script = { exports: {} };
    runInThisContext(`(function (module) {\n${source}\n})`, { filename: path })(script);

    return script.exports;
};

const { limits, canSubmit } = load('public/js/classic-timeline-compose.js');

const form = (attributes) => ({
    getAttribute: (name) => (name in attributes ? attributes[name] : null),
    hasAttribute: (name) => name in attributes,
});

test('a form without a limit counts down from 140 and needs words', () => {
    assert.deepEqual(limits(form({})), { max: 140, allowImageOnly: false });
    assert.deepEqual(limits(form({ 'data-max-length': 'abc' })), { max: 140, allowImageOnly: false });
});

test('the talk box names its own limit and lets a picture go alone', () => {
    assert.deepEqual(limits(form({ 'data-max-length': '5000', 'data-allow-image-only': '' })), { max: 5000, allowImageOnly: true });
});

test('the submit gate', () => {
    assert.equal(canSubmit('', false, 5000, true), false, 'empty and no file');
    assert.equal(canSubmit('', true, 5000, true), true, 'empty with a file');
    assert.equal(canSubmit('', true, 140, false), false, 'a file alone where words are required');
    assert.equal(canSubmit('a'.repeat(5001), true, 5000, true), false, 'over the cap even with a file');
    assert.equal(canSubmit('a'.repeat(5000), false, 5000, true), true, 'at the cap');
    assert.equal(canSubmit('a'.repeat(141), false, 140, false), false, 'the timeline cap');
});
