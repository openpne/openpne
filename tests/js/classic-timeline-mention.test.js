import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { runInThisContext } from 'node:vm';

/**
 * The Classic @mention picker's state machine, which decides whether a mention survives an edit and
 * what range is submitted for it. It restates Modern's resources/js/lib/mention-draft.ts contract in
 * a script that ships unbuilt, so this is that file's case table run against the other copy — the
 * two surfaces post to one endpoint and may not disagree.
 *
 * The script is loaded the way the browser loads it (evaluated, not imported) with a `module` in
 * scope: that branch hands back the pure half and skips the DOM wiring. In *this* realm, so the
 * objects it returns carry the same Object.prototype that assert/strict compares against.
 */
const path = 'public/js/classic-timeline-mention.js';
const source = readFileSync(fileURLToPath(new URL(`../../${path}`, import.meta.url)), 'utf8');
const load = runInThisContext(`(function (module) {\n${source}\n})`, { filename: path });
const script = { exports: {} };
load(script);

const { detectTrigger, keyAction, applyPick, applyEdit, toPayload, offeredCandidates } = script.exports;

const GRIN = '\u{1F600}';

const alice = { id: 7, name: 'Alice' };

/** The draft an insertion of `@Alice ` at `start` leaves behind. */
const picked = (start, name = 'Alice', memberId = 7) => ({ memberId, label: name, start });

// --- trigger detection

test('an @ at the start of the body opens a trigger', () => {
    assert.deepEqual(detectTrigger('@al', 3), { start: 0, end: 3, query: 'al' });
});

test('an @ right after a space opens a trigger', () => {
    assert.deepEqual(detectTrigger('hi @al', 6), { start: 3, end: 6, query: 'al' });
});

test('an @ after an ideographic space opens a trigger', () => {
    assert.deepEqual(detectTrigger('やあ　@あ', 5), { start: 3, end: 5, query: 'あ' });
});

test('an @ at the start of a line opens a trigger', () => {
    assert.deepEqual(detectTrigger('line1\n@al', 9), { start: 6, end: 9, query: 'al' });
});

test('an @ preceded by a non-space is an address, not a trigger', () => {
    assert.equal(detectTrigger('mail@example', 12), null);
});

test('the second @ of @@ opens nothing', () => {
    assert.equal(detectTrigger('@@al', 4), null);
});

test('a trigger does not reach across a space', () => {
    assert.equal(detectTrigger('@al ice', 7), null);
});

test('a trigger does not reach across a line break', () => {
    assert.equal(detectTrigger('@al\nice', 7), null);
});

test('the caret before the @ is not inside its trigger', () => {
    assert.equal(detectTrigger('@al', 0), null);
});

test('a 20 code point query still triggers and a 21st ends it', () => {
    assert.notEqual(detectTrigger(`@${'a'.repeat(20)}`, 21), null);
    assert.equal(detectTrigger(`@${'a'.repeat(21)}`, 22), null);
});

test('the query cap counts code points, not units', () => {
    // 20 astral emoji are 40 UTF-16 units but 20 code points, so they are still a query.
    assert.notEqual(detectTrigger(`@${GRIN.repeat(20)}`, 41), null);
    assert.equal(detectTrigger(`@${GRIN.repeat(21)}`, 43), null);
});

// --- which keys the picker takes

const openPicker = { open: true, composing: false };

test('an open picker takes the arrows, Enter, Tab and Escape', () => {
    assert.equal(keyAction('ArrowDown', openPicker), 'next');
    assert.equal(keyAction('ArrowUp', openPicker), 'previous');
    assert.equal(keyAction('Enter', openPicker), 'confirm');
    assert.equal(keyAction('Tab', openPicker), 'confirm');
    assert.equal(keyAction('Escape', openPicker), 'dismiss');
});

test('an open picker leaves every other key to the field', () => {
    assert.equal(keyAction('a', openPicker), null);
    assert.equal(keyAction('ArrowLeft', openPicker), null);
    assert.equal(keyAction('Backspace', openPicker), null);
});

test('with no list open Enter is a line break, not a pick', () => {
    assert.equal(keyAction('Enter', { open: false, composing: false }), null);
});

test('a converting IME keeps every key, so a commit is not read as a pick', () => {
    // The Enter that commits a conversion reaches keydown; taking it would insert a mention instead.
    assert.equal(keyAction('Enter', { open: true, composing: true }), null);
    assert.equal(keyAction('ArrowDown', { open: true, composing: true }), null);
    assert.equal(keyAction('Escape', { open: true, composing: true }), null);
});

// --- insertion

test('picking a candidate replaces the trigger with the handle and a space', () => {
    const trigger = detectTrigger('hi @al', 6);
    assert.notEqual(trigger, null);

    const result = applyPick([], 'hi @al', trigger, alice);
    assert.equal(result.value, 'hi @Alice ');
    assert.equal(result.caret, 10);
    assert.deepEqual(result.mentions, [picked(3)]);
});

test('picking keeps the text after the trigger and shifts a mention in it', () => {
    const before = '@Alice hi @bo!';
    const trigger = detectTrigger(before, 13);
    assert.notEqual(trigger, null);

    const result = applyPick([picked(0)], before, trigger, { id: 9, name: 'Bob' });
    assert.equal(result.value, '@Alice hi @Bob !');
    assert.deepEqual(result.mentions, [picked(0), picked(10, 'Bob', 9)]);
});

test('picking into an existing handle drops the mention it overwrote', () => {
    // The trailing space was deleted and typing resumed, so the trigger reopens on the same "@".
    const before = '@Alicx';
    const trigger = detectTrigger(before, 6);
    assert.deepEqual(trigger, { start: 0, end: 6, query: 'Alicx' });

    const result = applyPick([picked(0)], before, trigger, alice);
    assert.equal(result.value, '@Alice ');
    assert.deepEqual(result.mentions, [picked(0)]);
});

// --- carrying the draft across edits

test('text typed before a mention shifts it', () => {
    assert.deepEqual(applyEdit([picked(3)], 'hi @Alice ', 'hi!! @Alice '), [picked(5)]);
});

test('text typed after a mention leaves it alone', () => {
    assert.deepEqual(applyEdit([picked(0)], '@Alice ', '@Alice hi'), [picked(0)]);
});

test('a character typed against the mention edge belongs outside it', () => {
    // "@Alice" ends at 6, so an insertion at 6 is after the mention, not inside it.
    assert.deepEqual(applyEdit([picked(0)], '@Alice ', '@Alice! '), [picked(0)]);
});

test('deleting one character inside the label destroys the mention', () => {
    assert.deepEqual(applyEdit([picked(0)], '@Alice ', '@Alce '), []);
});

test('rewriting part of the label destroys the mention', () => {
    assert.deepEqual(applyEdit([picked(3)], 'hi @Alice ', 'hi @Alicia '), []);
});

test('deleting the @ destroys the mention', () => {
    assert.deepEqual(applyEdit([picked(3)], 'hi @Alice ', 'hi Alice '), []);
});

test('an edit destroys only the mention it reached into', () => {
    const draft = [picked(0), picked(7, 'Bob', 9)];
    assert.deepEqual(applyEdit(draft, '@Alice @Bob ', '@Alice @Bb '), [picked(0)]);
});

test('a draft with no mentions is returned as it came', () => {
    const draft = [];
    assert.equal(applyEdit(draft, 'a', 'ab'), draft);
});

// --- edits a pair of values cannot describe on its own

// Two members who share a display name, mentioned in that order.
const twoAlices = [picked(0, 'Alice', 1), picked(7, 'Alice', 2)];

test('deleting one of two same-named mentions gives up both, from either end', () => {
    // Deleting the first "@Alice " and deleting the second leave the identical pair of values, so
    // nothing in the pair says which member is still on screen. Guessing would point the link and
    // the notification at the one just deleted.
    const draft = applyEdit(twoAlices, '@Alice @Alice ', '@Alice ');
    assert.deepEqual(draft, []);
    assert.deepEqual(toPayload(draft, '@Alice '), []);
});

test('deleting one of three same-named mentions gives up all three', () => {
    const three = [picked(0, 'Alice', 1), picked(7, 'Alice', 2), picked(14, 'Alice', 3)];
    assert.deepEqual(applyEdit(three, '@Alice @Alice @Alice ', '@Alice @Alice '), []);
});

test('a same-named pair takes its ambiguity-spanning neighbour down with it', () => {
    // The shared "@" lets one reading eat into Bob's handle, so his fate differs between readings
    // too. He degrades to plain text with the pair — the honest cost of never guessing.
    const draft = applyEdit([...twoAlices, picked(14, 'Bob', 9)], '@Alice @Alice @Bob ', '@Alice @Bob ');
    assert.deepEqual(draft, []);
});

test('deleting the first of two differently named mentions gives up the second too', () => {
    // Ambiguous as well — the "@" left at 0 may be either mention's, so the readings disagree about
    // Bob. A unique label is not enough to keep him: the body may hold a hand-typed plain "@Bob"
    // (the feature's own contract), and a guess could promote that to a mention. The price of never
    // guessing is that Bob degrades to plain text here.
    const draft = applyEdit([picked(0, 'Alice', 1), picked(7, 'Bob', 9)], '@Alice @Bob ', '@Bob ');
    assert.deepEqual(draft, []);
    assert.deepEqual(toPayload(draft, '@Bob '), []);
});

test('a deleted mention never jumps onto a hand-typed copy of its handle', () => {
    // A picked "@Alice " followed by the same handle typed by hand (plain text by contract).
    // Deleting the picked one must not leave a mention: the surviving "@Alice" is the hand-typed
    // text, which the writer chose not to make a mention.
    const draft = applyEdit([picked(0, 'Alice', 1)], '@Alice @Alice ', '@Alice ');
    assert.deepEqual(draft, []);
    assert.deepEqual(toPayload(draft, '@Alice '), []);
});

test('deleting the last of two differently named mentions keeps the first', () => {
    assert.deepEqual(applyEdit([picked(0, 'Alice', 1), picked(7, 'Bob', 9)], '@Alice @Bob ', '@Alice '), [picked(0, 'Alice', 1)]);
});

// --- candidates belong to the query they answer

test('candidates answer the query they were searched for', () => {
    assert.deepEqual(offeredCandidates('al', { query: 'al', items: [alice] }), [alice]);
});

test('candidates from a shorter query neither show nor confirm once it grows', () => {
    // The search for "@a" has landed and the field is already at "@al": until the next search
    // answers there is nothing to offer, so Enter is a line break again rather than a pick.
    const stale = { query: 'a', items: [alice] };
    assert.deepEqual(offeredCandidates('al', stale), []);
    assert.equal(keyAction('Enter', { open: offeredCandidates('al', stale).length > 0, composing: false }), null);
    assert.equal(keyAction('Tab', { open: offeredCandidates('al', stale).length > 0, composing: false }), null);
});

test('no trigger offers nothing, whatever the last search returned', () => {
    assert.deepEqual(offeredCandidates(null, { query: 'al', items: [alice] }), []);
    assert.deepEqual(offeredCandidates('al', null), []);
});

// --- payload

test('a payload row carries the code-point range, not the unit range', () => {
    const value = `${GRIN} @Alice`;
    assert.deepEqual(toPayload([picked(3)], value), [{ member_id: 7, offset: 2, length: 6 }]);
});

test('a length counts the label in code points', () => {
    const value = `@${GRIN}${GRIN} `;
    assert.deepEqual(toPayload([picked(0, `${GRIN}${GRIN}`)], value), [{ member_id: 7, offset: 0, length: 3 }]);
});

test('a row whose text no longer reads as its handle is dropped', () => {
    // The draft still says "@Alice at 0" but the body no longer does — the last word on what is
    // submitted is the body itself.
    assert.deepEqual(toPayload([picked(0)], '@Alicia '), []);
});

test('rows come out ascending by offset', () => {
    const value = '@Bob @Alice';
    const draft = [picked(5), picked(0, 'Bob', 9)];
    assert.deepEqual(toPayload(draft, value), [
        { member_id: 9, offset: 0, length: 4 },
        { member_id: 7, offset: 5, length: 6 },
    ]);
});

