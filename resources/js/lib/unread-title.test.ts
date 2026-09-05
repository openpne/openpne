import assert from 'node:assert/strict';
import { test } from 'node:test';
import { withUnreadPrefix } from './unread-title.ts';

test('a waiting count leads the title', () => {
    assert.equal(withUnreadPrefix('Home - SNS', 3), '(3) Home - SNS');
});

test('nothing waiting leaves the title bare', () => {
    assert.equal(withUnreadPrefix('Home - SNS', 0), 'Home - SNS');
});

test('the count clamps at 99+ like the badge pill', () => {
    assert.equal(withUnreadPrefix('Home - SNS', 99), '(99) Home - SNS');
    assert.equal(withUnreadPrefix('Home - SNS', 100), '(99+) Home - SNS');
});

test('a title that itself opens with a parenthesised number keeps it', () => {
    assert.equal(withUnreadPrefix('(2026) Roadmap - SNS', 0), '(2026) Roadmap - SNS');
    assert.equal(withUnreadPrefix('(2026) Roadmap - SNS', 2), '(2) (2026) Roadmap - SNS');
});

test('parentheses elsewhere in the title are left alone', () => {
    assert.equal(withUnreadPrefix('Diary (draft) - SNS', 2), '(2) Diary (draft) - SNS');
    assert.equal(withUnreadPrefix('Diary (draft) - SNS', 0), 'Diary (draft) - SNS');
});
