import assert from 'node:assert/strict';
import { test } from 'node:test';
import { setUnreadTitleCount, unreadTitleCount, withUnreadPrefix } from './unread-title.ts';

test('a waiting count leads the title', () => {
    assert.equal(withUnreadPrefix('Home - SNS', 3), '(3) Home - SNS');
});

test('nothing waiting leaves the title bare', () => {
    assert.equal(withUnreadPrefix('Home - SNS', 0), 'Home - SNS');
});

test('a dropped count takes its prefix with it', () => {
    assert.equal(withUnreadPrefix('(3) Home - SNS', 0), 'Home - SNS');
});

test('re-applying replaces the prefix rather than stacking it', () => {
    assert.equal(withUnreadPrefix(withUnreadPrefix('Home - SNS', 3), 4), '(4) Home - SNS');
    assert.equal(withUnreadPrefix('(99+) Home - SNS', 2), '(2) Home - SNS');
});

test('the count clamps at 99+ like the badge pill', () => {
    assert.equal(withUnreadPrefix('Home - SNS', 99), '(99) Home - SNS');
    assert.equal(withUnreadPrefix('Home - SNS', 100), '(99+) Home - SNS');
});

test('parentheses elsewhere in the title are left alone', () => {
    assert.equal(withUnreadPrefix('Diary (draft) - SNS', 2), '(2) Diary (draft) - SNS');
    assert.equal(withUnreadPrefix('Diary (draft) - SNS', 0), 'Diary (draft) - SNS');
});

test('the store hands back what was last written', () => {
    setUnreadTitleCount(7);
    assert.equal(unreadTitleCount(), 7);

    setUnreadTitleCount(0);
    assert.equal(unreadTitleCount(), 0);
});
