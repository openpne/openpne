import assert from 'node:assert/strict';
import { test } from 'node:test';
import { bottomNavSections, NAV_SECTIONS, NO_CONTEXT_COMPONENTS, resolveChrome, visibleNavSections } from './member-chrome.ts';
import type { FeatureKey } from '../types/index.ts';

const allOn: Record<FeatureKey, boolean> = {
    diary: true,
    directMessage: true,
    timeline: true,
    group: true,
    groupTopic: true,
    communityEvent: true,
    friend: true,
};

const hrefs = (enabled: Record<FeatureKey, boolean>) => visibleNavSections(enabled).map((section) => section.href);

test('every section shows while every unit is on', () => {
    assert.deepEqual(hrefs(allOn), NAV_SECTIONS.map((section) => section.href));
});

test('a section goes with its unit', () => {
    assert.equal(hrefs({ ...allOn, diary: false }).includes('/diary/list'), false);
    assert.equal(hrefs({ ...allOn, directMessage: false }).includes('/message'), false);
    assert.equal(hrefs({ ...allOn, friend: false }).includes('/friend/list'), false);
    assert.equal(hrefs({ ...allOn, timeline: false }).includes('/timeline'), false);
    assert.equal(hrefs({ ...allOn, group: false }).includes('/groups'), false);
});

test('the untoggleable sections survive every unit being off', () => {
    const allOff = Object.fromEntries(Object.keys(allOn).map((key) => [key, false])) as Record<FeatureKey, boolean>;

    assert.deepEqual(hrefs(allOff), ['/notifications', '/member/search', '/member/config']);
});

test('the groups section stays while only a board is off', () => {
    // Topics and events have no section of their own, so nothing here answers to them.
    assert.equal(hrefs({ ...allOn, groupTopic: false, communityEvent: false }).includes('/groups'), true);
});

const bottomHrefs = (enabled: Record<FeatureKey, boolean>) => bottomNavSections(enabled).map((section) => section.href);

test('the bottom bar carries Home and its three sections in bar order', () => {
    assert.deepEqual(bottomHrefs(allOn), ['/dashboard', '/diary/list', '/notifications', '/message']);
});

test('a bottom tab goes with its unit', () => {
    assert.deepEqual(bottomHrefs({ ...allOn, diary: false }), ['/dashboard', '/notifications', '/message']);
    assert.deepEqual(bottomHrefs({ ...allOn, directMessage: false }), ['/dashboard', '/diary/list', '/notifications']);
});

test('Home and notifications survive every unit being off', () => {
    const allOff = Object.fromEntries(Object.keys(allOn).map((key) => [key, false])) as Record<FeatureKey, boolean>;

    assert.deepEqual(bottomHrefs(allOff), ['/dashboard', '/notifications']);
});

test('the Home tab matches its own path only', () => {
    // Prefix matching would light Home up on anything nested under /dashboard.
    const home = bottomNavSections(allOn).find((section) => section.href === '/dashboard');

    assert.deepEqual(home?.match, ['/dashboard']);
    assert.equal(home?.exact, true);
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

const owner = { id: 1, name: 'Owner', imageUrl: '/f/1', avatarColor: '#123456' };

test("the owner's diary archive carries the same tab strip", () => {
    const props = { owner, isOwner: true };

    assert.equal(tabHrefs('diary/list', allOn, props).length, 3);
    assert.deepEqual(tabHrefs('diary/list', { ...allOn, friend: false }, props), ['/diary/list', '/diary/listMember']);
});

const chrome = (component: string, props: Record<string, unknown>) =>
    resolveChrome(component, { enabledFeatures: allOn, ...props });

test('the dashboard carries the diary action without becoming a hub', () => {
    const dashboard = chrome('dashboard', {});

    assert.equal(dashboard.action?.href, '/diary/new');
    // 'embedded' keeps the frame from drawing a heading row: the action is the mobile FAB alone.
    assert.equal(dashboard.mode, 'embedded');
    assert.equal(dashboard.title, undefined);
});

test('the dashboard action goes with the diary unit', () => {
    assert.equal(resolveChrome('dashboard', { enabledFeatures: { ...allOn, diary: false } }).action, undefined);
});

test('a community-scoped page is scoped to the group', () => {
    const group = { id: 7, name: 'Cyclists', imageUrl: '/f/7' };

    assert.deepEqual(chrome('community/topic/index', { group, canPost: true }).scope, {
        kind: 'group',
        id: 7,
        name: 'Cyclists',
        imageUrl: '/f/7',
    });
    // The image is optional data, not an optional field: a group without one still scopes.
    assert.equal(chrome('community/members', { group: { ...group, imageUrl: null } }).scope?.imageUrl, null);
});

test("another member's list is scoped to that member", () => {
    assert.deepEqual(chrome('diary/list', { owner, isOwner: false }).scope, {
        kind: 'member',
        id: 1,
        name: 'Owner',
        imageUrl: '/f/1',
        avatarColor: '#123456',
    });
});

test("the viewer's own hub has no scope", () => {
    // The hub bar carries the brand, not an identity block: the viewer is not somewhere else.
    const hub = chrome('diary/list', { owner, isOwner: true });

    assert.equal(hub.mode, 'section');
    assert.equal(hub.scope, undefined);
});

const cyclists = { id: 7, name: 'Cyclists', imageUrl: null };

/**
 * Every screen the registry classifies as a form. The flag is a behavior contract — chrome that
 * stays put while the reader works through the screen — so the set is enumerated here rather than
 * sampled: a screen joining or leaving it is a UX decision, not an implementation detail.
 */
const FORM_SCREENS: Record<string, Record<string, unknown>> = {
    'diary/new': {},
    'diary/edit': { diary: { id: 3, title: 'Draft' } },
    'timeline/new': {},
    'timeline/community-new': { group: cyclists },
    'community/edit': { group: cyclists },
    'community/topic/edit': { group: cyclists, topic: null },
    'community/event/edit': { group: cyclists, event: null },
    'message/compose': { parentId: null, parentSubject: null },
    'message/edit': {},
    'member/avatar': {},
    'member/edit-profile': {},
    'member/config/email': {},
    'member/config/password': {},
    'member/config/mfa': {},
    'member/config/notifications': {},
    'member/config/withdrawal': {},
    // Full-page confirmations and an invitation: no draft to lose, but the same "finish it or leave"
    // screen, so the chrome holds still for them too.
    'block/add': {},
    'block/remove': {},
    'friend/link': {},
    'member/invite': {},
};

/**
 * Every screen the registry classifies as a compose sheet — the forms whose whole job is writing one
 * thing. Enumerated for the same reason FORM_SCREENS is: below lg these lose the bar and the bottom
 * nav for a full-page sheet, so joining or leaving the set is a UX decision, not an implementation
 * detail. Every entry is also a FORM_SCREEN (compose implies form).
 */
const COMPOSE_SCREENS: Record<string, Record<string, unknown>> = {
    'diary/new': {},
    'diary/edit': { diary: { id: 3, title: 'Draft' } },
    'timeline/new': {},
    'timeline/community-new': { group: cyclists },
    'community/topic/edit': { group: cyclists, topic: null },
    'community/event/edit': { group: cyclists, event: null },
    'message/compose': { parentId: null, parentSubject: null },
    'message/edit': {},
};

test('a compose screen is a form with no floating action', () => {
    for (const [component, props] of Object.entries(COMPOSE_SCREENS)) {
        const sheet = chrome(component, props);

        assert.equal(sheet.compose, true, component);
        assert.equal(sheet.form, true, component);
        // The sheet has no bottom bar to float above, and its actions live in the sheet header.
        assert.equal(sheet.action, undefined, component);
    }
});

test('a form outside the compose set keeps the ordinary bar', () => {
    // community/edit is the near miss: the create/edit form shares its component with group
    // settings, which is a settings screen and stays on the static-trail bar.
    for (const [component, props] of Object.entries(FORM_SCREENS)) {
        if (component in COMPOSE_SCREENS) {
            continue;
        }
        assert.ok(!chrome(component, props).compose, component);
    }
});

test('a form keeps its context but takes no scope', () => {
    // The bar's contract: a form has nothing to be inside, so its trail stays static text.
    for (const [component, props] of Object.entries(FORM_SCREENS)) {
        const form = chrome(component, props);

        assert.equal(form.form, true, component);
        assert.equal(form.scope, undefined, component);
        // The entry points classified as crumbless have no trail to keep; every other form does.
        if (!NO_CONTEXT_COMPONENTS.includes(component)) {
            assert.ok((form.context?.length ?? 0) > 0, component);
        }
    }
});

const counterparties = (count: number) =>
    Array.from({ length: count }, (_, i) => ({ id: i + 1, name: `Member ${i + 1}`, imageUrl: null, avatarColor: null }));

test('a message is scoped to its counterparty only when there is exactly one', () => {
    const show = (count: number) => chrome('message/show', { message: { box: 'receive', counterparties: counterparties(count) } });

    // Withdrawn-only (0) and a multi-recipient sent message (2+) have no single member to name.
    assert.equal(show(0).scope, undefined);
    assert.equal(show(1).scope?.name, 'Member 1');
    assert.equal(show(2).scope, undefined);
});
