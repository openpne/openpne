import assert from 'node:assert/strict';
import { test } from 'node:test';
import { profileIsBlank } from './profile-blank.ts';

const zeroStats = { diaries: 0, activity: 0, friends: 0, communities: 0 };

test('blank when there are no details and no digest (guest view)', () => {
    assert.equal(profileIsBlank(null, null, 0, null), true);
});

test('blank when every stat is zero', () => {
    assert.equal(profileIsBlank(null, null, 0, zeroStats), true);
});

test('an activity-only member is not blank even though no preview section renders', () => {
    assert.equal(profileIsBlank(null, null, 0, { ...zeroStats, activity: 1 }), false);
});

test('any profile detail wins over an empty digest', () => {
    assert.equal(profileIsBlank('hello', null, 0, zeroStats), false);
    assert.equal(profileIsBlank(null, 30, 0, zeroStats), false);
    assert.equal(profileIsBlank(null, null, 1, zeroStats), false);
});
