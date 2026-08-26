import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { runInThisContext } from 'node:vm';

/**
 * The Reply link's quote: OpenPNE 3 appended ">>N name" plus a newline to whatever the box held,
 * and did nothing when no comment box was on the page.
 */
const path = 'public/js/classic-comment-reply.js';
const source = readFileSync(fileURLToPath(new URL(`../../${path}`, import.meta.url)), 'utf8');
const load = runInThisContext(`(function (module) {\n${source}\n})`, { filename: path });
const script = { exports: {} };
load(script);

const { appendQuote, replyTarget } = script.exports;

test('the quote is appended as its own line after what the box already holds', () => {
    assert.equal(appendQuote('', '3', 'Alice'), '>>3 Alice\n');
    assert.equal(appendQuote('draft', '12', 'Bob Ross'), 'draft>>12 Bob Ross\n');
});

test('a link names its box through data-comment-reply', () => {
    const box = { value: '' };
    const link = { getAttribute: (name) => (name === 'data-comment-reply' ? '#comment_body' : null) };
    const doc = { querySelector: (selector) => (selector === '#comment_body' ? box : null) };

    assert.equal(replyTarget(link, doc), box);
});

test('no box on the page leaves the link a plain link', () => {
    const link = { getAttribute: () => '#comment_body' };

    assert.equal(replyTarget(link, { querySelector: () => null }), null);
    assert.equal(replyTarget({ getAttribute: () => null }, { querySelector: () => ({}) }), null);
});
