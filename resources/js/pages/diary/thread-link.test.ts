import assert from 'node:assert/strict';
import { test } from 'node:test';
import { diaryThreadLink } from './thread-link.ts';

test('the default view carries only the size', () => {
    assert.equal(diaryThreadLink(7, 20, 1, false), '/diary/7?size=20');
});

test('a non-default order and a later page are spelled as the Classic pager spells them', () => {
    assert.equal(diaryThreadLink(7, 20, 3, true), '/diary/7?size=20&order=asc&page=3');
    assert.equal(diaryThreadLink(7, 20, 2, false), '/diary/7?size=20&page=2');
});

test('the size a reader arrived with is carried forward', () => {
    assert.equal(diaryThreadLink(7, 100, 2, false), '/diary/7?size=100&page=2');
});
