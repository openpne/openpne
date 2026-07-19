import type { ComponentType } from 'react';
import { Activity, Bell, BookOpen, Mail, Pencil, Plus, Search, Settings, UserCircle2, Users } from 'lucide-react';

/**
 * The member-surface chrome registry: the single source for what the nav and the page frame render
 * per section — nav label/icon/badge, hub h1, tabs, and the primary action. NavItems and MemberFrame
 * both read it, so a hub's h1 IS its nav label by construction (they share the key), and a screen
 * missing from the registry still gets the default frame — consistency is the default, not an opt-in.
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
    /** Scope crumbs above the heading (community, and for board content the board). */
    context?: { href: string; label: string | ChromeLabel }[];
}

export interface NavSection {
    href: string;
    /** Canonical URL prefix marking this section active. */
    match: string;
    icon: Icon;
    label: ChromeLabel;
    badge?: { count: 'friendRequests' | 'unreadMessages' | 'notifications'; label: ChromeLabel };
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

/** Nav order and metadata (Home is the brand row, so it is omitted). */
export const NAV_SECTIONS: NavSection[] = [
    { href: '/diary/list', match: '/diary', icon: BookOpen, label: DIARIES },
    // '/community' also prefixes /communityTopic|Event, so board pages keep this section active.
    { href: '/community/search', match: '/community', icon: Users, label: COMMUNITIES },
    { href: '/timeline', match: '/timeline', icon: Activity, label: ACTIVITY },
    {
        href: '/friend/list',
        match: '/friend',
        icon: UserCircle2,
        label: FRIENDS,
        badge: { count: 'friendRequests', label: t(':count pending %friend% requests') },
    },
    {
        href: '/message',
        match: '/message',
        icon: Mail,
        label: MESSAGES,
        badge: { count: 'unreadMessages', label: t(':count unread messages') },
    },
    {
        href: '/notifications',
        match: '/notifications',
        icon: Bell,
        label: NOTIFICATIONS,
        badge: { count: 'notifications', label: t(':count unread notifications') },
    },
    { href: '/member/search', match: '/member/search', icon: Search, label: MEMBER_SEARCH },
    { href: '/member/config', match: '/member/config', icon: Settings, label: SETTINGS },
];

const WRITE_DIARY: ChromeAction = { href: '/diary/new', label: t('Write a %diary%'), icon: Pencil };
const POST_ACTIVITY: ChromeAction = { href: '/timeline/new', label: t('%Post_activity%'), icon: Pencil };
const CREATE_COMMUNITY: ChromeAction = { href: '/community/edit', label: t('Create a %community%'), icon: Plus };

const diaryTabs = (active: 'all' | 'friends' | 'mine'): ChromeTab[] => [
    { href: '/diary/list', label: t('All'), active: active === 'all' },
    { href: '/diary/listFriend', label: FRIENDS, active: active === 'friends' },
    { href: '/diary/listMember', label: t('My %diaries%'), active: active === 'mine' },
];

const communityTabs = (active: 'browse' | 'joined' | 'recent'): ChromeTab[] => [
    { href: '/community/search', label: t('All'), active: active === 'browse' },
    { href: '/community/joinList', label: t('Joined'), active: active === 'joined' },
    { href: '/community/recent', label: t('Recent activity'), active: active === 'recent' },
];

const friendTabs = (active: 'list' | 'manage'): ChromeTab[] => [
    { href: '/friend/list', label: FRIENDS, active: active === 'list' },
    { href: '/friend/manage', label: t('Requests'), active: active === 'manage' },
];

interface CommunityRef {
    id: number;
    name: string;
}

const communityContext = (community: CommunityRef): Chrome['context'] => [
    { href: `/community/${community.id}`, label: community.name },
];

// Board-scoped context: the community crumb plus the board itself, shared by a board's detail
// (show) and edit pages — an edit page adds the specific topic/event as a third crumb.
const topicBoardContext = (community: CommunityRef): Chrome['context'] => [
    ...communityContext(community)!,
    { href: `/communityTopic/listCommunity/${community.id}`, label: t('%Topics%') },
];

const eventBoardContext = (community: CommunityRef): Chrome['context'] => [
    ...communityContext(community)!,
    { href: `/communityEvent/listCommunity/${community.id}`, label: t('Events') },
];

interface MemberRef {
    id: number;
    name: string;
}

// A contextual page about another member (their diary archive, friends, communities): crumb back
// to that member's profile, the closest thing those lists have to a canonical parent. The crumb is
// the one place the chrome shows the member's name — titles stay generic (FRIENDS, not ":name's
// %friends%") so the same string never renders twice back to back.
const memberContext = (member: MemberRef): Chrome['context'] => [
    { href: `/member/${member.id}`, label: member.name },
];

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

/**
 * Hub chrome per Inertia component, computed from page props where a component doubles as the
 * viewer's hub and another member's contextual list (owner → hub chrome, non-owner → contextual
 * title, no tabs/action).
 */
const HUB_CHROME: Record<string, (props: Record<string, unknown>) => Partial<Chrome>> = {
    'diary/feed': (props) => ({
        mode: 'section',
        title: DIARIES,
        tabsLabel: DIARIES,
        tabs: diaryTabs((props as { variant: string }).variant === 'friends' ? 'friends' : 'all'),
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
                  tabs: diaryTabs('mine'),
                  action: WRITE_DIARY,
              }
            : { mode: 'contextual', title: DIARIES, context: memberContext(owner) };
    },
    'diary/show': (props) => {
        const { diary } = props as unknown as { diary: { author: MemberRef } };
        return {
            context: [{ href: `/diary/listMember/${diary.author.id}`, label: t(":name's %diary%", { name: diary.author.name }) }],
        };
    },
    'diary/edit': (props) => {
        const { diary } = props as unknown as { diary: { id: number; title: string } };
        return { context: [{ href: `/diary/${diary.id}`, label: diary.title }] };
    },
    'community/search': () => ({
        mode: 'section',
        title: COMMUNITIES,
        tabsLabel: COMMUNITIES,
        tabs: communityTabs('browse'),
        action: CREATE_COMMUNITY,
    }),
    // The three community tabs are one hub: same h1 (= nav label) and the create action on every
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
            : { mode: 'contextual', title: COMMUNITIES, context: memberContext(owner) };
    },
    'community/recent': () => ({
        mode: 'section',
        title: COMMUNITIES,
        tabsLabel: COMMUNITIES,
        tabs: communityTabs('recent'),
        action: CREATE_COMMUNITY,
    }),
    // Community-scoped pages carry the community as context crumbs; board indexes keep a short h1
    // ("Topics" / "Events") so a long community name never wraps the heading. Detail pages add the
    // board as a second crumb (the back-to-board path they used to carry in the body).
    'community/topic/index': (props) => {
        const { community, canPost } = props as unknown as { community: CommunityRef; canPost: boolean };
        return {
            mode: 'contextual',
            title: t('%Topics%'),
            context: communityContext(community),
            action: canPost
                ? { href: `/communityTopic/new/${community.id}`, label: t('Create a %topic%'), icon: Plus }
                : undefined,
        };
    },
    'community/event/index': (props) => {
        const { community, canPost } = props as unknown as { community: CommunityRef; canPost: boolean };
        return {
            mode: 'contextual',
            title: t('Events'),
            context: communityContext(community),
            action: canPost
                ? { href: `/communityEvent/new/${community.id}`, label: t('Create an event'), icon: Plus }
                : undefined,
        };
    },
    'community/topic/show': (props) => {
        const { community } = props as unknown as { community: CommunityRef };
        return { context: topicBoardContext(community) };
    },
    'community/event/show': (props) => {
        const { community } = props as unknown as { community: CommunityRef };
        return { context: eventBoardContext(community) };
    },
    // Edit mode's third crumb is the topic/event being edited (the page it returns to on cancel);
    // create mode stops at the board, matching diary/edit vs diary/new.
    'community/topic/edit': (props) => {
        const { community, topic } = props as unknown as { community: CommunityRef; topic: { id: number; name: string } | null };
        return {
            context: topic
                ? [...topicBoardContext(community)!, { href: `/communityTopic/${topic.id}`, label: topic.name }]
                : topicBoardContext(community),
        };
    },
    'community/event/edit': (props) => {
        const { community, event } = props as unknown as { community: CommunityRef; event: { id: number; name: string } | null };
        return {
            context: event
                ? [...eventBoardContext(community)!, { href: `/communityEvent/${event.id}`, label: event.name }]
                : eventBoardContext(community),
        };
    },
    'community/pending': (props) => {
        const { community } = props as unknown as { community: CommunityRef };
        return { mode: 'contextual', title: t('Pending members'), context: communityContext(community) };
    },
    // Edit mode crumbs to the community; create mode to the hub the create action lives on.
    'community/edit': (props) => {
        const { community } = props as unknown as { community: CommunityRef | null };
        return community
            ? { context: communityContext(community) }
            : { context: [{ href: '/community/search', label: COMMUNITIES }] };
    },
    // The h1-as-link pattern these replaced put the community/event name in the h1 itself; the
    // crumb now carries it, so the h1 shrinks to the plain section label (existing keys reused).
    'community/members': (props) => {
        const { community } = props as unknown as { community: CommunityRef };
        return { mode: 'contextual', title: t('Members'), context: communityContext(community) };
    },
    'community/manage': (props) => {
        const { community } = props as unknown as { community: CommunityRef };
        return { mode: 'contextual', title: t('Management member'), context: communityContext(community) };
    },
    'community/event/members': (props) => {
        const { community, event } = props as unknown as { community: CommunityRef; event: { id: number; name: string } };
        return {
            mode: 'contextual',
            title: t('Count of Member'),
            context: [...eventBoardContext(community)!, { href: `/communityEvent/${event.id}`, label: event.name }],
        };
    },
    'timeline/index': () => ({ mode: 'section', title: ACTIVITY, action: POST_ACTIVITY }),
    'timeline/member': (props) => {
        const { owner, isOwner } = props as unknown as OwnerScoped;
        return isOwner
            ? { mode: 'section', title: ACTIVITY, action: POST_ACTIVITY }
            : { mode: 'contextual', title: ACTIVITY, context: memberContext(owner) };
    },
    // Crumb label is the bare author name, the post card right below carries the same name as
    // content; the page's h1 is a generic post label so nothing renders twice.
    'timeline/show': (props) => {
        const { post } = props as unknown as { post: { author: MemberRef } };
        return {
            context: [{ href: `/member/${post.author.id}/timeline`, label: post.author.name }],
        };
    },
    'friend/list': (props) => {
        const { owner, isOwner } = props as unknown as OwnerScoped;
        return isOwner
            ? { mode: 'section', title: FRIENDS, tabsLabel: FRIENDS, tabs: friendTabs('list') }
            : { mode: 'contextual', title: FRIENDS, context: memberContext(owner) };
    },
    'friend/manage': () => ({
        mode: 'section',
        title: FRIENDS,
        tabsLabel: FRIENDS,
        tabs: friendTabs('manage'),
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
        const { message } = props as unknown as { message: { box: MessageBoxSlug } };
        return { context: [MESSAGE_BOX_PARENT[message.box]] };
    },
    // Reply crumbs back to the original message (its box, then the message itself); a fresh compose
    // crumbs to the Messages hub. parentSubject is null except when replying.
    'message/compose': (props) => {
        const { parentId, parentSubject } = props as unknown as { parentId: number | null; parentSubject: string | null };
        return {
            gap: '6',
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
    'block/add': { width: 'narrow' },
    'block/remove': { width: 'narrow' },
    'friend/link': { width: 'narrow' },
    'member/invite': { width: 'narrow' },
    'block/list': { gap: '6' },
    'member/avatar': { gap: '6', context: CONFIG_CONTEXT },
    'member/edit-profile': { gap: '6', context: CONFIG_CONTEXT },
    'member/show': { gap: '6' },
    'message/edit': { gap: '6', context: [MESSAGE_BOX_PARENT.draft] },
    'member/config/email': { gap: '6', context: CONFIG_CONTEXT },
    'member/config/password': { gap: '6', context: CONFIG_CONTEXT },
    'member/config/mfa': { gap: '6', context: CONFIG_CONTEXT },
    'member/config/notifications': { gap: '6', context: CONFIG_CONTEXT },
    'member/config/withdrawal': { gap: '6', context: CONFIG_CONTEXT },
    'community/show': { foreground: true },
    'community/topic/show': { foreground: true },
    'community/event/show': { foreground: true },
    'diary/show': { foreground: true },
    'diary/new': { context: [{ href: '/diary/list', label: DIARIES }] },
    'timeline/show': { foreground: true },
    'timeline/new': { context: [{ href: '/timeline', label: ACTIVITY }] },
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
    'friend/manage',
    'member/show',
    'block/add',
    'block/list',
    'block/remove',
    'friend/link',
    'member/invite',
];

export function resolveChrome(
    component: string,
    props: Record<string, unknown>,
    override?: Partial<Chrome>,
): Chrome {
    const base: Chrome = { mode: 'embedded', width: 'standard', gap: '4' };
    return { ...base, ...STATIC_CHROME[component], ...HUB_CHROME[component]?.(props), ...override };
}
