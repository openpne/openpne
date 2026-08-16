import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    bottomNavSections,
    chromeRecedes,
    hasBottomNav,
    isHomeComponent,
    NAV_SECTIONS,
    NO_CONTEXT_COMPONENTS,
    resolveChrome,
    TALK_ROOMS_HREF,
    visibleNavSections,
} from './member-chrome.ts';
import type { FeatureKey } from '../types/index.ts';

const allOn: Record<FeatureKey, boolean> = {
    diary: true,
    directMessage: true,
    timeline: true,
    group: true,
    groupTopic: true,
    groupEvent: true,
    groupTalk: true,
    friend: true,
    mcp: true,
};

const hrefs = (enabled: Record<FeatureKey, boolean>) => visibleNavSections(enabled).map((section) => section.href);

test('every section shows while every unit is on', () => {
    assert.deepEqual(hrefs(allOn), NAV_SECTIONS.map((section) => section.href));
});

test('a section goes with its unit', () => {
    assert.equal(hrefs({ ...allOn, diary: false }).includes('/diary/list'), false);
    assert.equal(hrefs({ ...allOn, directMessage: false }).includes('/messages'), false);
    assert.equal(hrefs({ ...allOn, friend: false }).includes('/friend/list'), false);
    assert.equal(hrefs({ ...allOn, timeline: false }).includes('/timeline'), false);
    assert.equal(hrefs({ ...allOn, group: false }).includes('/groups/mine'), false);
});

test('the groups section lands on the joined list, where the badge it carries is explained', () => {
    // The badge counts groups with unread talk; only the joined list shows which ones.
    const groups = NAV_SECTIONS.find((section) => section.badge?.count === 'groupTalks');

    assert.equal(groups?.href, '/groups/mine');
    // Every group space still lights the entry up, joined list and browse alike.
    assert.deepEqual(groups?.match, ['/groups', '/topics', '/events']);
    // The sidebar's rooms nest under that same entry: they are what its badge is counting, and the
    // list's "view all" is the entry's own href.
    assert.equal(TALK_ROOMS_HREF, groups?.href);
});

test('the untoggleable sections survive every unit being off', () => {
    const allOff = Object.fromEntries(Object.keys(allOn).map((key) => [key, false])) as Record<FeatureKey, boolean>;

    assert.deepEqual(hrefs(allOff), ['/notifications', '/member/search', '/member/config']);
});

test('the groups section stays while only a board is off', () => {
    // Topics and events have no section of their own, so nothing here answers to them.
    assert.equal(hrefs({ ...allOn, groupTopic: false, groupEvent: false }).includes('/groups/mine'), true);
});

const bottomHrefs = (enabled: Record<FeatureKey, boolean>) => bottomNavSections(enabled).map((section) => section.href);

test('the bottom bar carries Home and its four sections in bar order', () => {
    assert.deepEqual(bottomHrefs(allOn), ['/dashboard', '/groups/mine', '/diary/list', '/notifications', '/messages']);
});

test('a bottom tab goes with its unit', () => {
    assert.deepEqual(bottomHrefs({ ...allOn, group: false }), [
        '/dashboard',
        '/diary/list',
        '/notifications',
        '/messages',
    ]);
    assert.deepEqual(bottomHrefs({ ...allOn, diary: false }), [
        '/dashboard',
        '/groups/mine',
        '/notifications',
        '/messages',
    ]);
    assert.deepEqual(bottomHrefs({ ...allOn, directMessage: false }), [
        '/dashboard',
        '/groups/mine',
        '/diary/list',
        '/notifications',
    ]);
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

const owner = { id: 1, name: 'Owner', imageUrl: '/f/1', avatarColor: '#123456', isAi: false };

test("the owner's diary archive carries the same tab strip", () => {
    const props = { owner, isOwner: true };

    assert.equal(tabHrefs('diary/list', allOn, props).length, 3);
    assert.deepEqual(tabHrefs('diary/list', { ...allOn, friend: false }, props), ['/diary/list', '/diary/listMember']);
});

test('the group hub leads with the joined list on every tab', () => {
    const joinedFirst = ['/groups/mine', '/groups', '/groups/recent'];

    assert.deepEqual(tabHrefs('community/list', allOn, { owner, isOwner: true }), joinedFirst);
    assert.deepEqual(tabHrefs('community/search', allOn, {}), joinedFirst);
    assert.deepEqual(tabHrefs('community/recent', allOn, {}), joinedFirst);
});

const chrome = (component: string, props: Record<string, unknown>) =>
    resolveChrome(component, { enabledFeatures: allOn, ...props });

test('the joined tab carries the unread-talk count', () => {
    // The one tab whose rows explain the nav badge, so it repeats it.
    const tabs = chrome('community/search', {}).tabs ?? [];

    assert.deepEqual(
        tabs.map((tab) => tab.badge?.count),
        ['groupTalks', undefined, undefined],
    );
    // The pill's aria-label is this phrase with the live count — the link's only announcement
    // of the number, so it must exist and must be the nav's own wording.
    assert.equal(tabs[0]?.badge?.label.key, ':count %communities% with new messages');
});

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

test('the unified home is the same screen as the dashboard it replaces', () => {
    // Same route, same chrome: the experiment switch must not change what the frame draws.
    assert.deepEqual(chrome('unified/home', {}), chrome('dashboard', {}));
    assert.equal(resolveChrome('unified/home', { enabledFeatures: { ...allOn, diary: false } }).action, undefined);
    assert.ok(NO_CONTEXT_COMPONENTS.includes('unified/home'));
    // Both take the mobile brand bar, not a back control — home has nothing above it.
    assert.ok(isHomeComponent('dashboard'));
    assert.ok(isHomeComponent('unified/home'));
    assert.ok(!isHomeComponent('diary/feed'));
});

test('the unified member page is the same screen as the profile it replaces', () => {
    // Same route, same chrome: the experiment switch must not change what the frame draws.
    assert.deepEqual(chrome('unified/member', {}), chrome('member/show', {}));
    assert.ok(NO_CONTEXT_COMPONENTS.includes('unified/member'));
    // A page about somebody else, so its mobile bar takes the back control rather than the brand row.
    assert.ok(!isHomeComponent('unified/member'));
});

test('the unified group page is the same screen as the group top it replaces', () => {
    // Same route, same chrome: the experiment switch must not change what the frame draws.
    assert.deepEqual(chrome('unified/group', {}), chrome('community/show', {}));
    assert.ok(NO_CONTEXT_COMPONENTS.includes('unified/group'));
    // A page about the group itself, so it takes the back control rather than the brand row and
    // scopes to nothing above it.
    assert.ok(!isHomeComponent('unified/group'));
    assert.equal(chrome('unified/group', {}).scope, undefined);
});

test('a community-scoped page is scoped to the group', () => {
    const group = { id: 7, name: 'Cyclists', imageUrl: '/f/7' };

    assert.deepEqual(chrome('group/topic/index', { group, canPost: true }).scope, {
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
        isAi: false,
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
    'community/edit': { group: cyclists },
    'group/topic/edit': { group: cyclists, topic: null },
    'group/event/edit': { group: cyclists, event: null },
    'message/edit': {},
    'message/new': {},
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
    'group/topic/edit': { group: cyclists, topic: null },
    'group/event/edit': { group: cyclists, event: null },
    'message/edit': {},
    // The one that submits nothing: picking a recipient is a navigation into their conversation.
    'message/new': {},
};

/**
 * Every screen the registry classifies as a conversation — a room someone stays in, reading and
 * writing in the same place. Enumerated for the same reason the two sets above are: below lg these
 * lose the bottom bar and stop receding, so joining or leaving the set is a UX decision.
 */
const CONVERSATION_SCREENS: Record<string, Record<string, unknown>> = {
    'group/talk/index': { group: cyclists },
    'message/conversation/index': { counterpart: owner },
};

test('a conversation drops the bottom bar and keeps its chrome still', () => {
    for (const [component, props] of Object.entries(CONVERSATION_SCREENS)) {
        const room = chrome(component, props);

        assert.equal(room.conversation, true, component);
        assert.equal(hasBottomNav(room), false, component);
        assert.equal(chromeRecedes(room), false, component);
        // Not a sheet: the bar keeps the back control and the room it names, and nothing floats over
        // a composer that is already on screen.
        assert.ok(!room.compose, component);
        assert.ok(!room.form, component);
        assert.notEqual(room.scope, undefined, component);
        assert.equal(room.action, undefined, component);
    }
});

test('the withdrawn bucket names itself, since it has no member to be scoped to', () => {
    // Every departed member's messages collapse into one conversation, so there is no profile for the
    // bar's identity block to link to — the heading carries who it is with instead.
    const bucket = chrome('message/conversation/index', { counterpart: null });

    assert.equal(bucket.conversation, true);
    assert.equal(bucket.scope, undefined);
    assert.deepEqual(bucket.title, { key: 'Withdrawn member', replacements: undefined });
    assert.notEqual(bucket.context, undefined);
});

test('an ordinary page keeps the bottom bar and lets its chrome recede', () => {
    for (const component of ['dashboard', 'notifications/index', 'community/show', 'group/topic/index']) {
        const page = chrome(component, { group: cyclists, canPost: true });

        assert.equal(hasBottomNav(page), true, component);
        assert.equal(chromeRecedes(page), true, component);
    }
});

test('a compose screen is a form with no floating action', () => {
    for (const [component, props] of Object.entries(COMPOSE_SCREENS)) {
        const sheet = chrome(component, props);

        assert.equal(sheet.compose, true, component);
        assert.equal(sheet.form, true, component);
        // The sheet has no bottom bar to float above, and its actions live in the sheet header.
        assert.equal(hasBottomNav(sheet), false, component);
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

const MESSAGES_LABEL = { key: 'Messages', replacements: undefined };

test('the messages entry counts conversations and lands where they are listed', () => {
    // The badge is the room-list reading: how many people are waiting, not how many rows.
    const messages = NAV_SECTIONS.find((section) => section.badge?.count === 'unreadMessages');

    assert.equal(messages?.href, '/messages');
    assert.equal(messages?.badge?.label.key, ':count conversations with new messages');
    // The mailbox URLs stay OpenPNE 3's and redirect here, so the bare prefix lights the entry for
    // both.
    assert.deepEqual(messages?.match, ['/message']);
});

test('a message-scoped screen crumbs back to the conversation list', () => {
    // The four boxes are gone from Modern, leaving one parent for the draft form and the withdrawn
    // bucket to return to.
    assert.deepEqual(chrome('message/edit', {}).context, [{ href: '/messages', label: MESSAGES_LABEL }]);
    assert.deepEqual(chrome('message/new', {}).context, [{ href: '/messages', label: MESSAGES_LABEL }]);
    assert.deepEqual(chrome('message/conversation/index', { counterpart: null }).context, [
        { href: '/messages', label: MESSAGES_LABEL },
    ]);
});

test('the Messages hub starts a message the way every other hub starts its own thing', () => {
    const hub = chrome('message/conversations/index', {});

    assert.equal(hub.mode, 'section');
    assert.deepEqual(hub.title, MESSAGES_LABEL);
    assert.equal(hub.action?.href, '/messages/new');
    assert.equal(hub.tabs, undefined);
});

test('the retired mailbox screens are gone from the registry', () => {
    // Their routes redirect into chat now; a registry entry left behind would be chrome for a page
    // that no longer renders.
    for (const component of ['message/index', 'message/show', 'message/compose']) {
        assert.deepEqual(chrome(component, {}), { mode: 'embedded', width: 'standard', gap: '4' }, component);
    }
});
