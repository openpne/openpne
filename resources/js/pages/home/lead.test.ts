import assert from 'node:assert/strict';
import { test } from 'node:test';
import { splitLead } from './lead.ts';
import type { MentionEntity, TagEntity } from '../../lib/entity-split.ts';

const mention = (offset: number, length: number, memberId = 3): MentionEntity => ({ offset, length, memberId });
const tag = (offset: number, length: number, name = 'book'): TagEntity => ({ offset, length, tag: name });

test('a one-line body is all lead, and keeps every entity', () => {
    const split = splitLead('Hi @rin #book', [mention(3, 4)], [tag(8, 5)]);

    assert.equal(split.lead, 'Hi @rin #book');
    assert.equal(split.rest, '');
    assert.deepEqual(split.leadMentions, [mention(3, 4)]);
    assert.deepEqual(split.leadTags, [tag(8, 5)]);
    assert.deepEqual(split.restMentions, []);
    assert.deepEqual(split.restTags, []);
});

test('the break sends each entity to the half it lies in, rebased', () => {
    // "Hi @rin" is 7 points, the newline is point 7, and the rest begins at 8.
    const split = splitLead('Hi @rin\nsee #book too', [mention(3, 4)], [tag(12, 5)]);

    assert.equal(split.lead, 'Hi @rin');
    assert.equal(split.rest, 'see #book too');
    assert.deepEqual(split.leadMentions, [mention(3, 4)]);
    assert.deepEqual(split.leadTags, []);
    assert.deepEqual(split.restTags, [tag(4, 5)]);
    assert.equal(split.rest.slice(4, 9), '#book');
});

test('an entity straddling the break is dropped from both halves', () => {
    // Half a mention is not a link to anyone, and keeping it truncated would draw a link over text
    // that no longer matches the name it was made from.
    const split = splitLead('ab@ri\nn cd', [mention(2, 5)]);

    assert.equal(split.lead, 'ab@ri');
    assert.equal(split.rest, 'n cd');
    assert.deepEqual(split.leadMentions, []);
    assert.deepEqual(split.restMentions, []);
});

test('offsets are code points, so an astral character does not shift the rest', () => {
    // The emoji is one code point and two UTF-16 units: cut by string index, the lead would end a
    // character early and every rebased offset in the rest would be one too high.
    const split = splitLead('🎉 @rin\nzz #tag', [mention(2, 4)], [tag(10, 4, 'tag')]);

    assert.equal(split.lead, '🎉 @rin');
    assert.equal(split.rest, 'zz #tag');
    assert.deepEqual(split.leadMentions, [mention(2, 4)]);
    assert.deepEqual(split.restTags, [tag(3, 4, 'tag')]);
    assert.equal(Array.from(split.rest).slice(3, 7).join(''), '#tag');
});
