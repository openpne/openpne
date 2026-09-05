import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { runInThisContext } from 'node:vm';

/**
 * The script is loaded the way the browser loads it (evaluated, not imported) with a `module` in
 * scope: that branch hands back the pure half and skips the DOM wiring.
 */
const load = (path) => {
    const source = readFileSync(fileURLToPath(new URL(`../../${path}`, import.meta.url)), 'utf8');
    const script = { exports: {} };
    runInThisContext(`(function (module) {\n${source}\n})`, { filename: path })(script);

    return script.exports;
};

const { bodyLength, canSubmit, errorText, clearsBox } = load('public/js/classic-timeline-replies.js');
const compose = load('public/js/classic-timeline-compose.js');

const GRIN = '\u{1F600}';

test('a body is measured in code points over server-normalized newlines', () => {
    assert.equal(bodyLength(''), 0);
    assert.equal(bodyLength(GRIN), 1); // String.length would say 2
    assert.equal(bodyLength('a\r\nb'), 3);
    assert.equal(bodyLength('a\rb'), 3);
});

test('the two Classic compose paths count the same body the same way', () => {
    // One endpoint, one max:140: a rule that drifted here would refuse a body the box accepted.
    for (const value of ['', GRIN, 'a\r\nb', 'a\rb', `${GRIN}\r\n${GRIN}`, 'hi @Alice #tag']) {
        assert.equal(bodyLength(value), compose.bodyLength(value), JSON.stringify(value));
    }
});

test('an empty body and one past 140 code points cannot be submitted', () => {
    assert.equal(canSubmit(''), false);
    assert.equal(canSubmit('a'), true);
    assert.equal(canSubmit('a'.repeat(140)), true);
    assert.equal(canSubmit('a'.repeat(141)), false);
    assert.equal(canSubmit(GRIN.repeat(140)), true); // 280 UTF-16 units, 140 code points
});

test("a refusal shows the validator's line", () => {
    assert.equal(errorText(422, { errors: { body: ['本文は140文字以内'] } }, 'fallback'), '本文は140文字以内');
});

test('every other refusal shows the page\'s own words, never the framework message', () => {
    // 401 / 419 / 429 answer with an English literal whatever the site's language.
    assert.equal(errorText(419, { message: 'CSRF token mismatch.' }, 'fallback'), 'fallback');
    assert.equal(errorText(429, { message: 'Too Many Attempts.' }, 'fallback'), 'fallback');
    assert.equal(errorText(422, { message: 'The given data was invalid.' }, 'fallback'), 'fallback');
    assert.equal(errorText(500, null, 'fallback'), 'fallback');
    assert.equal(errorText(0, undefined, 'fallback'), 'fallback');
    // An `errors` payload on any other status is not the validator's.
    assert.equal(errorText(403, { errors: { body: ['leaked'] } }, 'fallback'), 'fallback');
});

test('a landed post empties the box only while it still holds what was sent', () => {
    assert.equal(clearsBox('hello', 'hello'), true);
    assert.equal(clearsBox('hello', 'hello and more'), false);
    assert.equal(clearsBox('hello', ''), false);
});
