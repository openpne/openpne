import type { ComponentType } from 'react';
import { Activity, Bell, BookOpen, House, Mail, Pencil, Plus, Search, Settings, UserCircle2, Users } from 'lucide-react';
import type { FeatureKey, UnreadCounts } from '@/types';

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

/** Which of the shared `unread` counts a badge draws. */
export type BadgeCount = keyof UnreadCounts;

/**
 * A count from the shared `unread` props plus the phrase naming it — the pill's aria-label replaces
 * the bare digits, so the carrying link announces the number exactly once, in words.
 */
export interface CountBadge {
    count: BadgeCount;
    label: ChromeLabel;
}

export interface ChromeTab {
    href: string;
    label: ChromeLabel;
    active: boolean;
    badge?: CountBadge;
}

export interface ChromeAction {
    href: string;
    label: ChromeLabel;
    icon: Icon;
}

/** The entity a page sits inside, drawn as the mobile bar's identity block (image + name → its page). */
export type ChromeScope =
    | { kind: 'group'; id: number; name: string; imageUrl: string | null }
    | { kind: 'member'; id: number; name: string; imageUrl: string | null; avatarColor: string | null; isAi: boolean };

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
    /**
     * A screen where reading and writing sit together and the member stays: a conversation. Below lg
     * it draws no bottom bar and its chrome does not recede, so the composer stands at the true foot
     * of the screen — a bar that slid away under it would open a gap for the next message to show
     * through. Its bar keeps the back control and the scope identity: this is somewhere you go into,
     * not a sheet you close (←, not ✖).
     */
    conversation?: boolean;
}

/**
 * Whether the phone's bottom tab bar stands under this page. The shell renders the bar and reserves
 * its space (`--modern-bottom-offset`) from this one answer, so what is drawn and what is left clear
 * for it cannot disagree.
 */
export function hasBottomNav(chrome: Chrome): boolean {
    return !chrome.compose && !chrome.conversation;
}

/** Whether the mobile chrome recedes as the reader scrolls — see `form` and `conversation`. */
export function chromeRecedes(chrome: Chrome): boolean {
    return !chrome.form && !chrome.conversation;
}

export interface NavSection {
    href: string;
    /** URL prefixes marking this section active — several when a section spans more than one space. */
    match: string[];
    /** Match the whole path instead of the prefix: Home stands for one screen, not for what nests under it. */
    exact?: boolean;
    icon: Icon;
    label: ChromeLabel;
    badge?: CountBadge;
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

// Neither board has a section of its own, so this one answers for them too and for the
// container unit alone.
// The badge counts groups with something new in their talk, not messages — see
// CountGroupsWithUnreadTalk. It rides the container unit's entry because talk has no section of
// its own, the way this entry already answers for both boards. The href is the joined list
// rather than the browse tab: the badge sends a member here to find out which group is waiting,
// and only the joined rows carry that.
const GROUPS_SECTION: NavSection = {
    href: '/groups/mine',
    match: ['/groups', '/topics', '/events'],
    icon: Users,
    label: COMMUNITIES,
    badge: { count: 'groupTalks', label: t(':count %communities% with new messages') },
    feature: 'group',
};

/** Named because the unified chrome draws its bell from it: one entry, one phrase for the count. */
export const NOTIFICATIONS_SECTION: NavSection & { badge: CountBadge } = {
    href: '/notifications',
    match: ['/notifications'],
    icon: Bell,
    label: NOTIFICATIONS,
    badge: { count: 'notifications', label: t(':count unread notifications') },
};

/** Named for the same reason: the unified bottom bar's search zone is this entry. */
export const MEMBER_SEARCH_SECTION: NavSection = {
    href: '/member/search',
    match: ['/member/search'],
    icon: Search,
    label: MEMBER_SEARCH,
};

/** Nav order and metadata (Home is the brand row, so it is omitted). */
export const NAV_SECTIONS: NavSection[] = [
    { href: '/diary/list', match: ['/diary'], icon: BookOpen, label: DIARIES, feature: 'diary' },
    GROUPS_SECTION,
    { href: '/timeline', match: ['/timeline'], icon: Activity, label: ACTIVITY, feature: 'timeline' },
    {
        href: '/friend/list',
        match: ['/friend'],
        icon: UserCircle2,
        label: FRIENDS,
        badge: { count: 'friendRequests', label: t(':count pending %friend% requests') },
        feature: 'friend',
    },
    // The badge counts conversations with something new, not messages — see
    // CountUnreadConversations, as the groups entry above counts rooms. The match stays the bare
    // `/message` prefix, which covers the conversation list and the mailbox URLs alike.
    {
        href: '/messages',
        match: ['/message'],
        icon: Mail,
        label: MESSAGES,
        badge: { count: 'unreadMessages', label: t(':count conversations with new messages') },
        feature: 'directMessage',
    },
    NOTIFICATIONS_SECTION,
    MEMBER_SEARCH_SECTION,
    { href: '/member/config', match: ['/member/config'], icon: Settings, label: SETTINGS },
];

/** Whether a path (query and hash already stripped) is inside a section — see NavSection.exact. */
export function isSectionActive(section: NavSection, path: string): boolean {
    return section.exact ? section.match.includes(path) : section.match.some((prefix) => path.startsWith(prefix));
}

/**
 * The entry the desktop room list nests under. Talk has no section of its own, so the rooms hang
 * under the one whose badge they explain — and the same href is where the list's "view all" goes.
 */
export const TALK_ROOMS_HREF = '/groups/mine';

/** The nav an administrator's current toggles leave: a section whose unit is off answers 404. */
export function visibleNavSections(enabled: Record<FeatureKey, boolean>): NavSection[] {
    return NAV_SECTIONS.filter((section) => section.feature === undefined || enabled[section.feature]);
}

/** Home is the brand row in the nav lists, so it exists only for the bottom bar, which has no brand. */
const HOME_SECTION: NavSection = { href: '/dashboard', match: ['/dashboard'], exact: true, icon: House, label: t('Home') };

/**
 * The components the home route renders — the digest dashboard or, behind SnsSettingKey::
 * ModernUnifiedHome, the unified layout. The brand's own screen either way: the mobile bar shows the
 * brand row rather than a back control, since there is nothing above home to go back to.
 */
export function isHomeComponent(component: string): boolean {
    return component === 'dashboard' || component === 'unified/home';
}

/**
 * The phone bottom bar's tabs after Home, in bar order. A deliberately fixed list — one change
 * point for the composition — rather than something an administrator picks; per-site composition is
 * a later question, and a unit switched off still drops its tab through visibleNavSections.
 */
const BOTTOM_NAV_HREFS = ['/groups/mine', '/diary/list', '/notifications', '/messages'];

export function bottomNavSections(enabled: Record<FeatureKey, boolean>): NavSection[] {
    const visible = visibleNavSections(enabled);

    return [
        HOME_SECTION,
        ...BOTTOM_NAV_HREFS.map((href) => visible.find((section) => section.href === href)).filter(
            (section) => section !== undefined,
        ),
    ];
}

/**
 * The unified layout's top-bar tab pair: the two places it moves a member between. Nav entries, so
 * which paths light a tab up is the nav's own answer (Home its exact path, the group tab every group
 * space) and the group tab goes with its unit the way the drawer's entry does.
 */
export function unifiedTabs(enabled: Record<FeatureKey, boolean>): NavSection[] {
    return enabled.group ? [HOME_SECTION, GROUPS_SECTION] : [HOME_SECTION];
}

const WRITE_DIARY: ChromeAction = { href: '/diary/new', label: t('Write a %diary%'), icon: Pencil };
const POST_ACTIVITY: ChromeAction = { href: '/timeline/new', label: t('%Post_activity%'), icon: Pencil };
const CREATE_COMMUNITY: ChromeAction = { href: '/groups/edit', label: t('Create a %community%'), icon: Plus };
const NEW_MESSAGE: ChromeAction = { href: '/messages/new', label: t('New message'), icon: Pencil };

// The friend tab is a lens the friend unit owns, so it goes with the unit while the diary hub stays.
const diaryTabs = (active: 'all' | 'friends' | 'mine', friend: boolean): ChromeTab[] => [
    { href: '/diary/list', label: t('All'), active: active === 'all' },
    ...(friend ? [{ href: '/diary/listFriend', label: FRIENDS, active: active === 'friends' }] : []),
    { href: '/diary/listMember', label: t('My %diaries%'), active: active === 'mine' },
];

// Joined leads: it is where the nav lands, and browsing is the occasional errand.
const communityTabs = (active: 'browse' | 'joined' | 'recent'): ChromeTab[] => [
    {
        href: '/groups/mine',
        label: t('Joined'),
        active: active === 'joined',
        // The same phrase the nav badge uses, so the two announcements cannot drift apart.
        badge: { count: 'groupTalks', label: t(':count %communities% with new messages') },
    },
    { href: '/groups', label: t('All'), active: active === 'browse' },
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

const eventBoardContext = (group: CommunityRef): Chrome['context'] => [
    ...communityContext(group)!,
    { href: `/groups/${group.id}/events`, label: t('Events') },
];

interface MemberRef {
    id: number;
    name: string;
    imageUrl: string | null;
    avatarColor: string | null;
    isAi: boolean;
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
    isAi: member.isAi,
});

// The one place messages are listed, and so the parent every message-scoped screen crumbs back to.
const MESSAGES_HUB: Chrome['context'] = [{ href: '/messages', label: MESSAGES }];

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
    // The experimental home behind SnsSettingKey::ModernUnifiedHome: the same screen, so the same
    // chrome as the dashboard it replaces.
    'unified/home': (props) => ({ action: enabled(props, 'diary') ? WRITE_DIARY : undefined }),
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
            conversation: true,
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
        const { post } = props as unknown as { post: { author: MemberRef } };
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
    // The Messages hub: the conversations, with the drafts box under them. Its action opens the
    // recipient picker, the way every other hub's opens the thing that hub is a list of.
    'message/conversations/index': () => ({ mode: 'section', title: MESSAGES, action: NEW_MESSAGE }),
    // The conversation is the page, so no action button. Its counterpart is the room's identity: the
    // bar's scope while they still exist, and the heading otherwise — a withdrawn bucket has no
    // member page for a scope to link to, and no name for the bar to carry.
    'message/conversation/index': (props) => {
        const { counterpart } = props as unknown as { counterpart: MemberRef | null };

        return {
            mode: 'contextual',
            title: counterpart ? MESSAGES : t('Withdrawn member'),
            context: counterpart ? memberContext(counterpart) : MESSAGES_HUB,
            scope: counterpart ? memberScope(counterpart) : undefined,
            conversation: true,
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
    // The experimental member page behind SnsSettingKey::ModernUnifiedHome: the same screen as the
    // profile it replaces, so the same chrome.
    'unified/member': { gap: '6' },
    'message/edit': { gap: '6', form: true, compose: true, context: MESSAGES_HUB },
    // A sheet like the draft form, though it submits nothing: choosing who to write to is one screen
    // with one job, and it is left by picking a name or by closing it.
    'message/new': { gap: '6', form: true, compose: true, context: MESSAGES_HUB },
    'member/config/email': { gap: '6', form: true, context: CONFIG_CONTEXT },
    'member/config/password': { gap: '6', form: true, context: CONFIG_CONTEXT },
    'member/config/mfa': { gap: '6', form: true, context: CONFIG_CONTEXT },
    'member/config/notifications': { gap: '6', form: true, context: CONFIG_CONTEXT },
    'member/config/withdrawal': { gap: '6', form: true, context: CONFIG_CONTEXT },
    'member/config/ai/index': { gap: '6', form: true, context: CONFIG_CONTEXT },
    'member/config/ai/show': { gap: '6', form: true, context: CONFIG_CONTEXT },
    'community/show': { foreground: true },
    // The experimental group page behind SnsSettingKey::ModernUnifiedHome: the same screen as the
    // group top it replaces, so the same chrome.
    'unified/group': { foreground: true },
    'group/topic/show': { foreground: true },
    'group/event/show': { foreground: true },
    'diary/show': { foreground: true },
    'diary/new': { form: true, compose: true, context: [{ href: '/diary/list', label: DIARIES }] },
    'timeline/show': { foreground: true },
    'timeline/new': { form: true, compose: true, context: [{ href: '/timeline', label: ACTIVITY }] },
};

/**
 * Modern components with intentionally no context crumb, checked by ChromeContextCoverageTest so a
 * new page cannot land unclassified. Hub tops and tab-switch pages have no parent to crumb to;
 * member/show and community/show are top-level entities with no parent context; the rest
 * are orphaned entry points with no inbound nav today (tracked separately, out of this pass's scope).
 */
export const NO_CONTEXT_COMPONENTS: readonly string[] = [
    'dashboard',
    'unified/home',
    'diary/feed',
    'community/search',
    'community/recent',
    'community/show',
    'unified/group',
    'timeline/index',
    'message/conversations/index',
    'member/search',
    'member/config',
    'notifications/index',
    'friend/requests',
    'member/show',
    'unified/member',
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

/** Where the member is, and the way back to its top. A name is member text, so it is a plain string. */
export interface DivePlace {
    label: ChromeLabel | string;
    href: string;
}

/** The `{id, name}` every place-bearing prop and every scope share. */
interface PlaceRef {
    id: number;
    name: string;
}

const groupPlace = (group: PlaceRef): DivePlace => ({ label: group.name, href: `/groups/${group.id}` });

const memberPlace = (member: PlaceRef): DivePlace => ({ label: member.name, href: `/member/${member.id}` });

/** Not inside anything. */
const HOME_PLACE: DivePlace = { label: HOME_SECTION.label, href: HOME_SECTION.href };

/**
 * The pages that *are* a place, so no scope points at it — a group top and a profile are the top
 * everything under them scopes back to. Read from their own props for that reason: scope alone would
 * put a member standing on a group's front page nowhere.
 */
const DIVE_PLACES: Record<string, (props: Record<string, unknown>) => DivePlace> = {
    'community/show': (props) => groupPlace((props as unknown as { group: PlaceRef }).group),
    'unified/group': (props) => groupPlace((props as unknown as { group: PlaceRef }).group),
    'member/show': (props) => memberPlace((props as unknown as { profile: { owner: PlaceRef } }).profile.owner),
    'unified/member': (props) => memberPlace((props as unknown as { profile: PlaceRef }).profile),
};

/**
 * Where the member has dived to, for the unified bottom bar's middle zone: the group or the person
 * whose space they are in, and the way back up to its top. A hub, a form, the search and the
 * notification list are not places to be inside — from those the answer is home.
 */
export function divePlace(component: string, props: Record<string, unknown>, chrome: Chrome): DivePlace {
    const own = DIVE_PLACES[component];
    if (own) {
        return own(props);
    }

    const { scope } = chrome;
    if (scope?.kind === 'group') {
        return groupPlace(scope);
    }
    if (scope?.kind === 'member') {
        return memberPlace(scope);
    }

    return HOME_PLACE;
}
