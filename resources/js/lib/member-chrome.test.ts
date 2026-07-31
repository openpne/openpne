import assert from 'node:assert/strict';
import { test } from 'node:test';
import { NAV_SECTIONS, resolveChrome, visibleNavSections } from './member-chrome.ts';
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

const tabHrefs = (component: string, enabledFeatures: Record<FeatureKey, boolean>, props: Record<string, unknown>) =>
    (resolveChrome(component, { enabledFeatures, ...props }).tabs ?? []).map((tab) => tab.href);

test('the diary hub offers the friend feed while friends are on', () => {
    assert.deepEqual(tabHrefs('diary/feed', allOn, { variant: 'recent' }), [
        '/diary/list',
        '/diary/listFriend',
        '/diary/listMember',
    ]);
});

test('the diary hub drops the friend tab while friends are off', () => {
    // The friend lens goes with its unit; the hub and its remaining tabs stay.
    assert.deepEqual(tabHrefs('diary/feed', { ...allOn, friend: false }, { variant: 'recent' }), [
        '/diary/list',
        '/diary/listMember',
    ]);
});

test("the owner's diary archive carries the same tab strip", () => {
    const props = { owner: { id: 1, name: 'Owner' }, isOwner: true };

    assert.equal(tabHrefs('diary/list', allOn, props).length, 3);
    assert.deepEqual(tabHrefs('diary/list', { ...allOn, friend: false }, props), ['/diary/list', '/diary/listMember']);
});
