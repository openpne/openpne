import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    badgePhrase,
    commentsPhrase,
    entriesPhrase,
    jumpToUnreadPhrase,
    membersPhrase,
    messagesPhrase,
    participantsPhrase,
    repliesPhrase,
    unreadMessagesPhrase,
} from './count-phrase.ts';

const t = (key: string, replacements: Record<string, string | number> = {}): string =>
    Object.entries(replacements).reduce((line, [name, value]) => line.replaceAll(`:${name}`, String(value)), key);

const badge = { count: 'notifications' as const, label: { key: ':count unread notifications' }, one: { key: '1 unread notification' } };

test('one is said in the singular, everything else with its count', () => {
    assert.equal(badgePhrase(t, badge, 1), '1 unread notification');
    assert.equal(badgePhrase(t, badge, 2), '2 unread notifications');
    assert.equal(unreadMessagesPhrase(t, 1), '1 unread message');
    assert.equal(unreadMessagesPhrase(t, 12), '12 unread messages');
    assert.equal(jumpToUnreadPhrase(t, 1), 'Jump to 1 unread message');
    assert.equal(jumpToUnreadPhrase(t, 3), 'Jump to 3 unread messages');
});

test('each counted noun has its singular at one', () => {
    const phrases = [
        [membersPhrase, '1 member', '2 members'],
        [commentsPhrase, '1 comment', '2 comments'],
        [repliesPhrase, '1 reply', '2 replies'],
        [participantsPhrase, '1 participant', '2 participants'],
        [entriesPhrase, '1 entry', '2 entries'],
        [messagesPhrase, '1 message', '2 messages'],
    ] as const;
    for (const [phrase, one, two] of phrases) {
        assert.equal(phrase(t, 1), one);
        assert.equal(phrase(t, 2), two);
        assert.equal(phrase(t, 0), two.replace('2', '0'));
    }
});

test('zero is not one', () => {
    assert.equal(badgePhrase(t, badge, 0), '0 unread notifications');
    assert.equal(unreadMessagesPhrase(t, 0), '0 unread messages');
});
