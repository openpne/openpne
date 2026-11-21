import assert from 'node:assert/strict';
import { test } from 'node:test';
import type { ChromeScope } from './member-chrome.ts';
import { placeSections } from './place-sections.ts';

const allOpen = { talk: true, topic: true, event: true };

const allOn = { diary: true, directMessage: true, timeline: true, group: true, groupTopic: true, groupEvent: true, groupTalk: true, friend: true, mcp: true };

const group = (section: 'talk' | 'topic' | 'event' | 'members', open = allOpen): ChromeScope => ({
    kind: 'group',
    id: 7,
    name: 'Cyclists',
    imageUrl: null,
    section,
    open,
});

test("a group's sections run top, talk, topics, events, members, each to its own list", () => {
    assert.deepEqual(
        placeSections(group('talk'), 'group/talk/index', allOn).map(({ key, href }) => [key, href]),
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
        placeSections(group('members', closed), 'community/members', allOn).map(({ key }) => key),
        ['top', 'members'],
    );
    assert.deepEqual(
        placeSections(group('members', { talk: true, topic: false, event: true }), 'community/members', allOn).map(({ key }) => key),
        ['top', 'talk', 'event', 'members'],
    );
});

test("the section's own list is the page itself; a screen deeper inside marks its section as the current one", () => {
    const current = (section: Parameters<typeof group>[0], component: string) =>
        placeSections(group(section), component, allOn)
            .filter((item) => item.current !== undefined)
            .map(({ key, current }) => [key, current]);

    assert.deepEqual(current('topic', 'group/topic/index'), [['topic', 'page']]);
    assert.deepEqual(current('topic', 'group/topic/show'), [['topic', 'true']]);
    assert.deepEqual(current('event', 'group/event/members'), [['event', 'true']]);
    assert.deepEqual(current('members', 'community/members'), [['members', 'page']]);
    assert.deepEqual(current('members', 'community/pending'), [['members', 'true']]);
});

const member = (section: 'diary' | 'timeline' | 'groups' | 'friends' | null): ChromeScope => ({
    kind: 'member',
    id: 4,
    name: 'Owner',
    imageUrl: null,
    avatarColor: null,
    isAi: false,
    section,
});

test("a member's sections run top, diary, activity, groups, friends, each to the list their page links to", () => {
    assert.deepEqual(
        placeSections(member('diary'), 'diary/show', allOn).map(({ key, href }) => [key, href]),
        [
            ['top', '/member/4'],
            ['diary', '/diary/listMember/4'],
            ['timeline', '/member/4/timeline'],
            ['groups', '/groups/mine?id=4'],
            ['friends', '/friend/list?id=4'],
        ],
    );
});

test("a switched-off unit takes its section out of a member's place, and the top stays", () => {
    assert.deepEqual(
        placeSections(member('diary'), 'diary/show', { ...allOn, timeline: false, friend: false }).map(({ key }) => key),
        ['top', 'diary', 'groups'],
    );
    assert.deepEqual(
        placeSections(member(null), 'message/conversation/index', { ...allOn, diary: false, timeline: false, group: false, friend: false }).map(({ key }) => key),
        ['top'],
    );
});

test("a member's lists and the screens under them mark their section, and a conversation marks none", () => {
    const current = (section: Parameters<typeof member>[0], component: string) =>
        placeSections(member(section), component, allOn)
            .filter((item) => item.current !== undefined)
            .map(({ key, current }) => [key, current]);

    assert.deepEqual(current('diary', 'diary/list'), [['diary', 'page']]);
    assert.deepEqual(current('diary', 'diary/show'), [['diary', 'true']]);
    assert.deepEqual(current('timeline', 'timeline/member'), [['timeline', 'page']]);
    assert.deepEqual(current('timeline', 'timeline/show'), [['timeline', 'true']]);
    assert.deepEqual(current('groups', 'community/list'), [['groups', 'page']]);
    assert.deepEqual(current('friends', 'friend/list'), [['friends', 'page']]);
    assert.deepEqual(current(null, 'message/conversation/index'), []);
});

test('every tile names itself in words, and the top is the same word in every place', () => {
    assert.deepEqual(
        placeSections(group('talk'), 'group/talk/index', allOn).map(({ label }) => label.key),
        ['Top page', 'Talk', '%Topics%', 'Events', 'Members'],
    );
    assert.deepEqual(
        placeSections(member('diary'), 'diary/show', allOn).map(({ label }) => label.key),
        ['Top page', '%Diaries%', '%Activity%', '%Communities%', '%Friends%'],
    );
});
