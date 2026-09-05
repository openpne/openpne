import assert from 'node:assert/strict';
import { test } from 'node:test';
import { conversationVisitOptions } from './opening-scroll.ts';

const preserves = (options: { preserveScroll?: unknown }, component: string): boolean | undefined =>
    conversationVisitOptions(options).preserveScroll?.({ component });

test('a conversation keeps the scroll it gave itself', () => {
    // `<Link>` sends this on every navigation, so it is what an ordinary visit looks like here.
    assert.equal(preserves({ preserveScroll: false }, 'group/talk/index'), true);
    assert.equal(preserves({ preserveScroll: false }, 'message/conversation/index'), true);
});

test('every other page is still placed by Inertia', () => {
    assert.equal(preserves({ preserveScroll: false }, 'diary/list'), false);
    assert.equal(preserves({}, 'group/topic/show'), false);
});

test('a visit that asked to keep its scroll is left alone', () => {
    // Overriding these would undo `<Link preserveScroll>`, a `preserveScroll: true` post and
    // `router.reload()` — every one of which resolves to a page this policy would answer `false` for.
    assert.deepEqual(conversationVisitOptions({ preserveScroll: true }), {});
    assert.deepEqual(conversationVisitOptions({ preserveScroll: 'errors' }), {});
    assert.deepEqual(conversationVisitOptions({ preserveScroll: () => true }), {});
});
