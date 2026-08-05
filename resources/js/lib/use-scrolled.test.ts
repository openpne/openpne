import assert from 'node:assert/strict';
import { test } from 'node:test';
import { type PageScrolled, pastTop, renderedScrolled } from './use-scrolled.ts';

test('a single pixel of travel is off the top', () => {
    assert.equal(pastTop(0), false);
    assert.equal(pastTop(1), true);
    assert.equal(pastTop(200), true);
});

test('overscroll counts as the top', () => {
    // iOS reports a negative y while the page is dragged past its top; nothing is under the bar there.
    assert.equal(pastTop(-20), false);
});

const scrolled: PageScrolled = { scrolled: true, url: '/diary/new' };

test('a flag belongs to the page it was decided on', () => {
    assert.equal(renderedScrolled(scrolled, '/diary/new', true), true);
    // Inertia keeps the document across a navigation; the page arriving must paint unscrolled.
    assert.equal(renderedScrolled(scrolled, '/diary/edit/1', true), false);
});

test('a disabled caller is never scrolled', () => {
    assert.equal(renderedScrolled(scrolled, '/diary/new', false), false);
});
