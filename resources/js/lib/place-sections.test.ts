import assert from 'node:assert/strict';
import { test } from 'node:test';
import type { ChromeScope } from './member-chrome.ts';
import { placeSections } from './place-sections.ts';

const allOpen = { talk: true, topic: true, event: true };

const group = (section: 'top' | 'talk' | 'topic' | 'event' | 'members', open = allOpen): ChromeScope => ({
    kind: 'group',
    id: 7,
    name: 'Cyclists',
    imageUrl: null,
    section,
    open,
});

test("a group's sections run top, talk, topics, events, members, each to its own list", () => {
    assert.deepEqual(
        placeSections(group('talk'), 'group/talk/index').map(({ key, href }) => [key, href]),
        [
            ['top', '/groups/7'],
            ['talk', '/groups/7/talk'],
            ['topic', '/groups/7/topics'],
            ['event', '/groups/7/events'],
            ['members', '/groups/7/members'],
        ],
    );
});

test('a board the viewer may not open is not offered, while the top and the members list always are', () => {
    const closed = { talk: false, topic: false, event: false };

    assert.deepEqual(
        placeSections(group('top', closed), 'group/show').map(({ key }) => key),
        ['top', 'members'],
    );
    assert.deepEqual(
        placeSections(group('top', { talk: true, topic: false, event: true }), 'group/show').map(({ key }) => key),
        ['top', 'talk', 'event', 'members'],
    );
});

test("the section's own list is the page itself; a screen deeper inside marks its section as the current one", () => {
    const current = (section: Parameters<typeof group>[0], component: string) =>
        placeSections(group(section), component)
            .filter((item) => item.current !== undefined)
            .map(({ key, current }) => [key, current]);

    assert.deepEqual(current('top', 'group/show'), [['top', 'page']]);
    assert.deepEqual(current('topic', 'group/topic/index'), [['topic', 'page']]);
    assert.deepEqual(current('topic', 'group/topic/show'), [['topic', 'true']]);
    assert.deepEqual(current('event', 'group/event/members'), [['event', 'true']]);
    assert.deepEqual(current('members', 'community/members'), [['members', 'page']]);
    assert.deepEqual(current('members', 'community/pending'), [['members', 'true']]);
});

test('every tile names itself in words, and the top is the same word in every place', () => {
    const items = placeSections(group('top'), 'group/show');

    assert.deepEqual(
        items.map(({ label }) => label.key),
        ['Top page', 'Talk', '%Topics%', 'Events', 'Members'],
    );
});
