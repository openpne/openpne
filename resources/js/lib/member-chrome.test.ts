import assert from 'node:assert/strict';
import { test } from 'node:test';
import { NAV_SECTIONS, visibleNavSections } from './member-chrome.ts';
import type { FeatureKey } from '../types/index.ts';

const allOn: Record<FeatureKey, boolean> = {
    diary: true,
    message: true,
    timeline: true,
    community: true,
    communityTopic: true,
    communityEvent: true,
    friend: true,
};

const hrefs = (enabled: Record<FeatureKey, boolean>) => visibleNavSections(enabled).map((section) => section.href);

test('every section shows while every unit is on', () => {
    assert.deepEqual(hrefs(allOn), NAV_SECTIONS.map((section) => section.href));
});

test('a section goes with its unit', () => {
    assert.equal(hrefs({ ...allOn, diary: false }).includes('/diary/list'), false);
    assert.equal(hrefs({ ...allOn, message: false }).includes('/message'), false);
    assert.equal(hrefs({ ...allOn, friend: false }).includes('/friend/list'), false);
    assert.equal(hrefs({ ...allOn, timeline: false }).includes('/timeline'), false);
    assert.equal(hrefs({ ...allOn, community: false }).includes('/community/search'), false);
});

test('the untoggleable sections survive every unit being off', () => {
    const allOff = Object.fromEntries(Object.keys(allOn).map((key) => [key, false])) as Record<FeatureKey, boolean>;

    assert.deepEqual(hrefs(allOff), ['/notifications', '/member/search', '/member/config']);
});

test('the communities section stays while only a board is off', () => {
    // Topics and events have no section of their own, so nothing here answers to them.
    assert.equal(hrefs({ ...allOn, communityTopic: false, communityEvent: false }).includes('/community/search'), true);
});
