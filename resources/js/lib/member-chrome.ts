import type { ComponentType } from 'react';
import { Activity, Bell, BookOpen, House, Mail, Pencil, Plus, Search, Settings, UserCircle2, Users } from 'lucide-react';
import type { FeatureKey } from '@/types';

/**
 * The member-surface chrome registry: the single source for what the nav and the page frame render
 * per section — nav label/icon/badge, hub h1, tabs, and the primary action. NavItems reads it, and
 * MemberLayout resolves it once per page for both the app shell (which page class the mobile top bar
 * is, and the mobile action FAB) and MemberFrame, so a hub's h1 IS its nav label by construction
 * (they share the key), and a screen missing from the registry still gets the default frame —
 * consistency is the default, not an opt-in.
 *
 * Everything here is data (label keys, hrefs, icon references): builders run outside React, so
 * translation happens in the consumer (useT). Per-page deviations live in the maps below, keyed by
 * Inertia component name; a page can also override via `Page.layout = (props) => ({ chrome: {…} })`
 * (Inertia merges the object into the default layout's props) — reserve that for one-offs.
 */

export interface ChromeLabel {
    key: string;
    replacements?: Record<string, string | number>;
}

// Named `t` so the i18n scanner (which recognizes keys wrapped by a t call) sees registry keys:
// this is the deferred form — it captures the key; the frame/nav translate at render with useT.
const t = (key: string, replacements?: Record<string, string | number>): ChromeLabel => ({ key, replacements });

type Icon = ComponentType<{ className?: string; strokeWidth?: number; 'aria-hidden'?: boolean }>;

export interface ChromeTab {
    href: string;
    label: ChromeLabel;
    active: boolean;
}

export interface ChromeAction {
    href: string;
    label: ChromeLabel;
    icon: Icon;
}

/** The entity a page sits inside, drawn as the mobile bar's identity block (image + name → its page). */
export type ChromeScope =
    | { kind: 'group'; id: number; name: string; imageUrl: string | null }
    | { kind: 'member'; id: number; name: string; imageUrl: string | null; avatarColor: string | null };

export interface Chrome {
    /**
     * section = hub header (h1 = nav label) from the registry; contextual = frame header with a
     * page-specific title; embedded = no frame header — the page body carries its own heading
     * (details, forms, the dashboard's sr-only h1, member/show's in-panel h1).
     */
    mode: 'section' | 'contextual' | 'embedded';
    title?: ChromeLabel;
    tabs?: ChromeTab[];
    /** aria-label for the tab strip (the section label). */
    tabsLabel?: ChromeLabel;
    /** Primary action button (rendered only for a signed-in member). */
    action?: ChromeAction;
    width: 'standard' | 'narrow';
    gap: '4' | '6' | '8';
    /** Detail pages keep the text-foreground their own <main> used to set. */
    foreground?: boolean;
    /** Scope crumbs above the heading (group, and for board content the board). */
    context?: { href: string; label: string | ChromeLabel }[];
    /**
     * Who this page belongs to, for the mobile bar's identity block. Absent on a form (its bar shows
     * the context as static text) and on a page that is its own subject (a group top, a profile).
     */
    scope?: ChromeScope;
    /**
     * A full-page edit / create / settings / confirmation screen. Its mobile bar carries no link
     * beside the back control, and its chrome stays where it is instead of receding as the reader
     * scrolls — a screen someone is working through must not move under them. An inline form (a
     * comment box, a search row, a hub's instant-save controls) does not make the screen one.
     */
    form?: boolean;
    /**
     * A screen whose whole job is writing one thing (compose implies `form`). Below lg its chrome is
     * replaced by a full-page sheet: the top-bar slot carries a lone close control with the back
     * control's semantics plus the page's own action(s), injected through ComposeSheetAction; the
     * bottom bar is not rendered; and the surface enters bottom-to-top (nothing under
     * prefers-reduced-motion). Desktop (lg+) is unchanged. `context` stays: it is the close control's
     * cold-load fallback and the desktop breadcrumb.
     */
    compose?: boolean;
}

export interface NavSection {
    href: string;
    /** URL prefixes marking this section active — several when a section spans more than one space. */
    match: string[];
    /** Match the whole path instead of the prefix: Home stands for one screen, not for what nests under it. */
    exact?: boolean;
    icon: Icon;
    label: ChromeLabel;
    badge?: { count: 'friendRequests' | 'unreadMessages' | 'notifications'; label: ChromeLabel };
    /** The unit that owns this section; absent for the ones an administrator cannot switch off. */
    feature?: FeatureKey;
}

// Section labels shared between the nav and the hub headers (the h1 = nav label invariant).
const DIARIES = t('%Diaries%');
const COMMUNITIES = t('%Communities%');
const ACTIVITY = t('%Activity%');
const FRIENDS = t('%Friends%');
const MESSAGES = t('Messages');
const NOTIFICATIONS = t('Notifications');
const MEMBER_SEARCH = t('Search members');
const SETTINGS = t('Settings');

export type PolicyKind = 'terms' | 'privacy';

/** Titles of the two policy pages, shared by the frame heading and the page's document title. */
export const POLICY_TITLES: Record<PolicyKind, ChromeLabel> = {
    terms: t('Terms of service'),
    privacy: t('Privacy policy'),
};

/** Nav order and metadata (Home is the brand row, so it is omitted). */
export const NAV_SECTIONS: NavSection[] = [
    { href: '/diary/list', match: ['/diary'], icon: BookOpen, label: DIARIES, feature: 'diary' },
    // Neither board has a section of its own, so this one answers for them too and for the
    // container unit alone.
    { href: '/groups', match: ['/groups', '/topics', '/events'], icon: Users, label: COMMUNITIES, feature: 'group' },
    { href: '/timeline', match: ['/timeline'], icon: Activity, label: ACTIVITY, feature: 'timeline' },
    {
        href: '/friend/list',
        match: ['/friend'],
        icon: UserCircle2,
        label: FRIENDS,
        badge: { count: 'friendRequests', label: t(':count pending %friend% requests') },
        feature: 'friend',
    },
    {
        href: '/message',
        match: ['/message'],
        icon: Mail,
        label: MESSAGES,
        badge: { count: 'unreadMessages', label: t(':count unread messages') },
        feature: 'directMessage',
    },
    {
        href: '/notifications',
        match: ['/notifications'],
        icon: Bell,
        label: NOTIFICATIONS,
        badge: { count: 'notifications', label: t(':count unread notifications') },
    },
    { href: '/member/search', match: ['/member/search'], icon: Search, label: MEMBER_SEARCH },
    { href: '/member/config', match: ['/member/config'], icon: Settings, label: SETTINGS },
];

/** The nav an administrator's current toggles leave: a section whose unit is off answers 404. */
export function visibleNavSections(enabled: Record<FeatureKey, boolean>): NavSection[] {
    return NAV_SECTIONS.filter((section) => section.feature === undefined || enabled[section.feature]);
}

/** Home is the brand row in the nav lists, so it exists only for the bottom bar, which has no brand. */
const HOME_SECTION: NavSection = { href: '/dashboard', match: ['/dashboard'], exact: true, icon: House, label: t('Home') };

/**
 * The phone bottom bar's tabs after Home, in bar order. A deliberately fixed list — one change
 * point for the composition — rather than something an administrator picks; per-site composition is
 * a later question, and a unit switched off still drops its tab through visibleNavSections.
 */
const BOTTOM_NAV_HREFS = ['/diary/list', '/notifications', '/message'];

export function bottomNavSections(enabled: Record<FeatureKey, boolean>): NavSection[] {
    const visible = visibleNavSections(enabled);

    return [
        HOME_SECTION,
        ...BOTTOM_NAV_HREFS.map((href) => visible.find((section) => section.href === href)).filter(
            (section) => section !== undefined,
        ),
    ];
}

const WRITE_DIARY: ChromeAction = { href: '/diary/new', label: t('Write a %diary%'), icon: Pencil };
const POST_ACTIVITY: ChromeAction = { href: '/timeline/new', label: t('%Post_activity%'), icon: Pencil };
const CREATE_COMMUNITY: ChromeAction = { href: '/groups/edit', label: t('Create a %community%'), icon: Plus };

// The friend tab is a lens the friend unit owns, so it goes with the unit while the diary hub stays.
const diaryTabs = (active: 'all' | 'friends' | 'mine', friend: boolean): ChromeTab[] => [
    { href: '/diary/list', label: t('All'), active: active === 'all' },
    ...(friend ? [{ href: '/diary/listFriend', label: FRIENDS, active: active === 'friends' }] : []),
    { href: '/diary/listMember', label: t('My %diaries%'), active: active === 'mine' },
];

const communityTabs = (active: 'browse' | 'joined' | 'recent'): ChromeTab[] => [
    { href: '/groups', label: t('All'), active: active === 'browse' },
    { href: '/groups/mine', label: t('Joined'), active: active === 'joined' },
    { href: '/groups/recent', label: t('Recent activity'), active: active === 'recent' },
];

const friendTabs = (active: 'list' | 'requests'): ChromeTab[] => [
    { href: '/friend/list', label: FRIENDS, active: active === 'list' },
    { href: '/friend/requests', label: t('Requests'), active: active === 'requests' },
];

interface CommunityRef {
    id: number;
    name: string;
    imageUrl: string | null;
}

const communityContext = (group: CommunityRef): Chrome['context'] => [
    { href: `/groups/${group.id}`, label: group.name },
];

const communityScope = (group: CommunityRef): ChromeScope => ({
    kind: 'group',
    id: group.id,
    name: group.name,
    imageUrl: group.imageUrl,
});

// Board-scoped context: the group crumb plus the board itself, shared by a board's detail
// (show) and edit pages — an edit page adds the specific topic/event as a third crumb.
const topicBoardContext = (group: CommunityRef): Chrome['context'] => [
    ...communityContext(group)!,
    { href: `/groups/${group.id}/topics`, label: t('%Topics%') },
];

const communityTimelineContext = (group: CommunityRef): Chrome['context'] => [
    ...communityContext(group)!,
    { href: `/groups/${group.id}/timeline`, label: ACTIVITY },
];

const eventBoardContext = (group: CommunityRef): Chrome['context'] => [
    ...communityContext(group)!,
    { href: `/groups/${group.id}/events`, label: t('Events') },
];

interface MemberRef {
    id: number;
    name: string;
    imageUrl: string | null;
    avatarColor: string | null;
}

// A contextual page about another member (their diary archive, friends, groups): crumb back
// to that member's profile, the closest thing those lists have to a canonical parent. The crumb is
// the one place the chrome shows the member's name — titles stay generic (FRIENDS, not ":name's
// %friends%") so the same string never renders twice back to back.
const memberContext = (member: MemberRef): Chrome['context'] => [
    { href: `/member/${member.id}`, label: member.name },
];

const memberScope = (member: MemberRef): ChromeScope => ({
    kind: 'member',
    id: member.id,
    name: member.name,
    imageUrl: member.imageUrl,
    avatarColor: member.avatarColor,
});

// The message page's own box map keeps the row paths/bulk actions; the hub tabs live here.
const messageTabs = (active: string): ChromeTab[] => [
    { href: '/message/receiveList', label: t('Inbox'), active: active === 'receive' },
    { href: '/message/sendList', label: t('Sent Message'), active: active === 'sent' },
    { href: '/message/draftList', label: t('Drafts'), active: active === 'draft' },
    { href: '/message/dustList', label: t('Trash'), active: active === 'trash' },
];

type MessageBoxSlug = 'receive' | 'sent' | 'draft' | 'trash';

// Where a message's box crumbs back to (message/show, message/edit's fixed drafts box).
const MESSAGE_BOX_PARENT: Record<MessageBoxSlug, { href: string; label: ChromeLabel }> = {
    receive: { href: '/message/receiveList', label: t('Inbox') },
    sent: { href: '/message/sendList', label: t('Sent Message') },
    draft: { href: '/message/draftList', label: t('Drafts') },
    trash: { href: '/message/dustList', label: t('Trash') },
};

const CONFIG_CONTEXT: Chrome['context'] = [{ href: '/member/config', label: SETTINGS }];

interface OwnerScoped {
    owner: MemberRef;
    isOwner: boolean;
}

// The shell shares the resolved unit map on every page (HandleInertiaRequests).
const enabled = (props: Record<string, unknown>, feature: FeatureKey): boolean =>
    (props as { enabledFeatures: Record<FeatureKey, boolean> }).enabledFeatures[feature];

/**
 * Hub chrome per Inertia component, computed from page props where a component doubles as the
 * viewer's hub and another member's contextual list (owner → hub chrome, non-owner → contextual
 * title, no tabs/action).
 */
const HUB_CHROME: Record<string, (props: Record<string, unknown>) => Partial<Chrome>> = {
    // The dashboard carries an action without being a hub: it stays 'embedded' (the page owns its
    // heading, and the desktop sidebar already stands the same pill), so this only feeds the mobile
    // FAB — the diary shortcut the diary-forward home is the place for.
    'dashboard': (props) => ({ action: enabled(props, 'diary') ? WRITE_DIARY : undefined }),
    // One component serves both policy pages, so which one the server rendered picks the heading.
    'policy/show': (props) => ({ mode: 'contextual', title: POLICY_TITLES[(props as { kind: PolicyKind }).kind], gap: '6' }),
    'diary/feed': (props) => ({
        mode: 'section',
        title: DIARIES,
        tabsLabel: DIARIES,
        tabs: diaryTabs((props as { variant: string }).variant === 'friends' ? 'friends' : 'all', enabled(props, 'friend')),
        action: WRITE_DIARY,
    }),
    // The viewer's own archive (listMember) is a hub tab alongside the feeds; another member's
    // archive is a contextual list crumbed back to their profile (community/list precedent).
    'diary/list': (props) => {
        const { owner, isOwner } = props as unknown as OwnerScoped;
        return isOwner
            ? {
                  mode: 'section',
                  title: DIARIES,
                  tabsLabel: DIARIES,
                  tabs: diaryTabs('mine', enabled(props, 'friend')),
                  action: WRITE_DIARY,
              }
            : { mode: 'contextual', title: DIARIES, context: memberContext(owner), scope: memberScope(owner) };
    },
    'diary/show': (props) => {
        const { diary } = props as unknown as { diary: { author: MemberRef } };
        return {
            context: [{ href: `/diary/listMember/${diary.author.id}`, label: t(":name's %diary%", { name: diary.author.name }) }],
            scope: memberScope(diary.author),
        };
    },
    'diary/edit': (props) => {
        const { diary } = props as unknown as { diary: { id: number; title: string } };
        return { form: true, compose: true, context: [{ href: `/diary/${diary.id}`, label: diary.title }] };
    },
    'community/search': () => ({
        mode: 'section',
        title: COMMUNITIES,
        tabsLabel: COMMUNITIES,
        tabs: communityTabs('browse'),
        action: CREATE_COMMUNITY,
    }),
    // The three group tabs are one hub: same h1 (= nav label) and the create action on every
    // tab, so switching tabs never shifts the header. A non-owner's list stays contextual.
    'community/list': (props) => {
        const { owner, isOwner } = props as unknown as OwnerScoped;
        return isOwner
            ? {
                  mode: 'section',
                  title: COMMUNITIES,
                  tabsLabel: COMMUNITIES,
                  tabs: communityTabs('joined'),
                  action: CREATE_COMMUNITY,
              }
            : { mode: 'contextual', title: COMMUNITIES, context: memberContext(owner), scope: memberScope(owner) };
    },
    'community/recent': () => ({
        mode: 'section',
        title: COMMUNITIES,
        tabsLabel: COMMUNITIES,
        tabs: communityTabs('recent'),
        action: CREATE_COMMUNITY,
    }),
    // Community-scoped pages carry the group as context crumbs; board indexes keep a short h1
    // ("Topics" / "Events") so a long group name never wraps the heading. Detail pages add the
    // board as a second crumb (the back-to-board path they used to carry in the body).
    'group/topic/index': (props) => {
        const { group, canPost } = props as unknown as { group: CommunityRef; canPost: boolean };
        return {
            mode: 'contextual',
            title: t('%Topics%'),
            context: communityContext(group),
            scope: communityScope(group),
            action: canPost
                ? { href: `/groups/${group.id}/topics/new`, label: t('Create a %topic%'), icon: Plus }
                : undefined,
        };
    },
    'group/event/index': (props) => {
        const { group, canPost } = props as unknown as { group: CommunityRef; canPost: boolean };
        return {
            mode: 'contextual',
            title: t('Events'),
            context: communityContext(group),
            scope: communityScope(group),
            action: canPost
                ? { href: `/groups/${group.id}/events/new`, label: t('Create an event'), icon: Plus }
                : undefined,
        };
    },
    // The conversation is the page, so no action button: its composer is always on screen.
    'group/talk/index': (props) => {
        const { group } = props as unknown as { group: CommunityRef };

        return {
            mode: 'contextual',
            title: t('Talk'),
            context: communityContext(group),
            scope: communityScope(group),
        };
    },
    'group/topic/show': (props) => {
        const { group } = props as unknown as { group: CommunityRef };
        return { context: topicBoardContext(group), scope: communityScope(group) };
    },
    'group/event/show': (props) => {
        const { group } = props as unknown as { group: CommunityRef };
        return { context: eventBoardContext(group), scope: communityScope(group) };
    },
    // Edit mode's third crumb is the topic/event being edited (the page it returns to on cancel);
    // create mode stops at the board, matching diary/edit vs diary/new.
    'group/topic/edit': (props) => {
        const { group, topic } = props as unknown as { group: CommunityRef; topic: { id: number; name: string } | null };
        return {
            form: true,
            compose: true,
            context: topic
                ? [...topicBoardContext(group)!, { href: `/topics/${topic.id}`, label: topic.name }]
                : topicBoardContext(group),
        };
    },
    'group/event/edit': (props) => {
        const { group, event } = props as unknown as { group: CommunityRef; event: { id: number; name: string } | null };
        return {
            form: true,
            compose: true,
            context: event
                ? [...eventBoardContext(group)!, { href: `/events/${event.id}`, label: event.name }]
                : eventBoardContext(group),
        };
    },
    'community/pending': (props) => {
        const { group } = props as unknown as { group: CommunityRef };
        return {
            mode: 'contextual',
            title: t('Pending members'),
            context: communityContext(group),
            scope: communityScope(group),
        };
    },
    // Edit mode crumbs to the group; create mode to the hub the create action lives on.
    'community/edit': (props) => {
        const { group } = props as unknown as { group: CommunityRef | null };
        return group
            ? { form: true, context: communityContext(group) }
            : { form: true, context: [{ href: '/groups', label: COMMUNITIES }] };
    },
    // The h1-as-link pattern these replaced put the community/event name in the h1 itself; the
    // crumb now carries it, so the h1 shrinks to the plain section label (existing keys reused).
    'community/members': (props) => {
        const { group } = props as unknown as { group: CommunityRef };
        return {
            mode: 'contextual',
            title: t('Members'),
            context: communityContext(group),
            scope: communityScope(group),
        };
    },
    'community/manage': (props) => {
        const { group } = props as unknown as { group: CommunityRef };
        return {
            mode: 'contextual',
            title: t('Management member'),
            context: communityContext(group),
            scope: communityScope(group),
        };
    },
    'group/event/members': (props) => {
        const { group, event } = props as unknown as { group: CommunityRef; event: { id: number; name: string } };
        return {
            mode: 'contextual',
            title: t('Count of Member'),
            context: [...eventBoardContext(group)!, { href: `/events/${event.id}`, label: event.name }],
            scope: communityScope(group),
        };
    },
    'timeline/index': () => ({ mode: 'section', title: ACTIVITY, action: POST_ACTIVITY }),
    'timeline/community': (props) => {
        const { group, canPost } = props as unknown as { group: CommunityRef; canPost: boolean };
        return {
            mode: 'contextual',
            title: ACTIVITY,
            context: communityContext(group),
            scope: communityScope(group),
            action: canPost
                ? { href: `/community/${group.id}/timeline/new`, label: t('%Post_activity%'), icon: Pencil }
                : undefined,
        };
    },
    // The crumb names the group, as the topic and event compose forms do: a member in several
    // groups has to be able to see which one they are posting into.
    'timeline/community-new': (props) => {
        const { group } = props as unknown as { group: CommunityRef };
        return { context: communityTimelineContext(group) };
    },
    'timeline/member': (props) => {
        const { owner, isOwner } = props as unknown as OwnerScoped;
        return isOwner
            ? { mode: 'section', title: ACTIVITY, action: POST_ACTIVITY }
            : { mode: 'contextual', title: ACTIVITY, context: memberContext(owner), scope: memberScope(owner) };
    },
    // A lens on the feed, so it crumbs back to the feed and names the tag in its own header.
    'timeline/tag': (props) => ({
        mode: 'contextual',
        title: t('%Activity% posts tagged #:tag', { tag: (props as { tag: string }).tag }),
        context: [{ href: '/timeline', label: ACTIVITY }],
    }),
    // Crumb label is the bare author name, the post card right below carries the same name as
    // content; the page's h1 is a generic post label so nothing renders twice.
    'timeline/show': (props) => {
        const { post, group } = props as unknown as { post: { author: MemberRef }; group: CommunityRef | null };
        // A group thread is about its group: the reader arrived from inside one, and the
        // author's timeline is not where the post lives.
        if (group) {
            return { context: communityTimelineContext(group), scope: communityScope(group) };
        }

        return {
            context: [{ href: `/member/${post.author.id}/timeline`, label: post.author.name }],
            scope: memberScope(post.author),
        };
    },
    'friend/list': (props) => {
        const { owner, isOwner } = props as unknown as OwnerScoped;
        return isOwner
            ? { mode: 'section', title: FRIENDS, tabsLabel: FRIENDS, tabs: friendTabs('list') }
            : { mode: 'contextual', title: FRIENDS, context: memberContext(owner), scope: memberScope(owner) };
    },
    'friend/requests': () => ({
        mode: 'section',
        title: FRIENDS,
        tabsLabel: FRIENDS,
        tabs: friendTabs('requests'),
    }),
    // One Messages hub: stable h1 (= nav label) with the four boxes as tabs; the active box lives
    // in the tabs and the browser Head title, not the h1.
    'message/index': (props) => ({
        mode: 'section',
        title: MESSAGES,
        tabsLabel: MESSAGES,
        tabs: messageTabs((props as { box: string }).box),
    }),
    'message/show': (props) => {
        const { message } = props as unknown as { message: { box: MessageBoxSlug; counterparties: MemberRef[] } };
        // One counterparty is a scope the bar can name. A sent message can carry several recipients
        // and a fully withdrawn thread none — neither resolves to a single member, so the bar keeps
        // the box label instead (the same exactly-one rule Classic's show header applies).
        const only = message.counterparties.length === 1 ? message.counterparties[0] : undefined;
        return { context: [MESSAGE_BOX_PARENT[message.box]], scope: only ? memberScope(only) : undefined };
    },
    // Reply crumbs back to the original message (its box, then the message itself); a fresh compose
    // crumbs to the Messages hub. parentSubject is null except when replying.
    'message/compose': (props) => {
        const { parentId, parentSubject } = props as unknown as { parentId: number | null; parentSubject: string | null };
        return {
            gap: '6',
            form: true,
            compose: true,
            context:
                parentId !== null && parentSubject !== null
                    ? [
                          MESSAGE_BOX_PARENT.receive,
                          // Legacy subjects can be empty; fall back as the box pages do so the
                          // crumb link keeps an accessible name.
                          { href: `/message/read/${parentId}`, label: parentSubject || t('(No subject)') },
                      ]
                    : [{ href: '/message', label: MESSAGES }],
        };
    },
    'member/search': () => ({ mode: 'section', title: MEMBER_SEARCH, gap: '6' }),
    'member/config': () => ({ mode: 'section', title: SETTINGS, gap: '8' }),
    'notifications/index': () => ({ mode: 'section', title: NOTIFICATIONS }),
};

/** Non-hub deviations from the frame defaults (width/gap/foreground), keyed by component name. */
const STATIC_CHROME: Record<string, Partial<Chrome>> = {
    'block/add': { width: 'narrow', form: true },
    'block/remove': { width: 'narrow', form: true },
    'friend/link': { width: 'narrow', form: true },
    'member/invite': { width: 'narrow', form: true },
    'block/list': { gap: '6' },
    'member/avatar': { gap: '6', form: true, context: CONFIG_CONTEXT },
    'member/edit-profile': { gap: '6', form: true, context: CONFIG_CONTEXT },
    'member/show': { gap: '6' },
    'message/edit': { gap: '6', form: true, compose: true, context: [MESSAGE_BOX_PARENT.draft] },
    'member/config/email': { gap: '6', form: true, context: CONFIG_CONTEXT },
    'member/config/password': { gap: '6', form: true, context: CONFIG_CONTEXT },
    'member/config/mfa': { gap: '6', form: true, context: CONFIG_CONTEXT },
    'member/config/notifications': { gap: '6', form: true, context: CONFIG_CONTEXT },
    'member/config/withdrawal': { gap: '6', form: true, context: CONFIG_CONTEXT },
    'community/show': { foreground: true },
    'group/topic/show': { foreground: true },
    'group/event/show': { foreground: true },
    'diary/show': { foreground: true },
    'diary/new': { form: true, compose: true, context: [{ href: '/diary/list', label: DIARIES }] },
    'timeline/show': { foreground: true },
    'timeline/new': { form: true, compose: true, context: [{ href: '/timeline', label: ACTIVITY }] },
    // Context comes from HUB_CHROME, which knows which group is being composed into.
    'timeline/community-new': { form: true, compose: true },
};

/**
 * Modern components with intentionally no context crumb, checked by ChromeContextCoverageTest so a
 * new page cannot land unclassified. Hub tops and tab-switch pages have no parent to crumb to;
 * member/show and community/show are top-level entities no surveyed SNS crumbs back from; the rest
 * are orphaned entry points with no inbound nav today (tracked separately, out of this pass's scope).
 */
export const NO_CONTEXT_COMPONENTS: readonly string[] = [
    'dashboard',
    'diary/feed',
    'community/search',
    'community/recent',
    'community/show',
    'timeline/index',
    'message/index',
    'member/search',
    'member/config',
    'notifications/index',
    'friend/requests',
    'member/show',
    'block/add',
    'block/list',
    'block/remove',
    'friend/link',
    'member/invite',
    // Reached from the footer / settings / the signed-out login screen, by a guest as well as a
    // member: there is no one parent to crumb back to.
    'policy/show',
];

export function resolveChrome(
    component: string,
    props: Record<string, unknown>,
    override?: Partial<Chrome>,
): Chrome {
    const base: Chrome = { mode: 'embedded', width: 'standard', gap: '4' };
    return { ...base, ...STATIC_CHROME[component], ...HUB_CHROME[component]?.(props), ...override };
}
