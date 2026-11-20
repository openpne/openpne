import type { ComponentType } from 'react';
import { House, Pencil, Plus, Rss, Search, Settings, UserPlus } from 'lucide-react';
import { CONTENT_KINDS } from './content-kinds.ts';
import type { FeatureKey, ModernHomeId, UnreadCounts } from '@/types';

/**
 * Builders run outside React, so everything here is data (label keys, hrefs, icon references) and
 * the consumer translates at render (docs/internals/feature-modules.md, "Surface responsibilities").
 */

export interface ChromeLabel {
    key: string;
    replacements?: Record<string, string | number>;
}

// Named `t` so the i18n scanner, which recognizes keys wrapped by a `t` call, sees registry keys.
const t = (key: string, replacements?: Record<string, string | number>): ChromeLabel => ({ key, replacements });

type Icon = ComponentType<{ className?: string; strokeWidth?: number; 'aria-hidden'?: boolean }>;

export type BadgeCount = keyof UnreadCounts;

/**
 * The pill hides its digits from assistive tech, so this phrase is the one place the number is
 * announced, in words.
 */
export interface CountBadge {
    count: BadgeCount;
    /** The phrase with `:count` in it. */
    label: ChromeLabel;
    /** The phrase at exactly one, where English drops the plural (lib/count-phrase.ts). */
    one: ChromeLabel;
}

export interface ChromeTab {
    href: string;
    label: ChromeLabel;
    active: boolean;
    badge?: CountBadge;
    /** A guest's frame keeps only the tabs marked so, since every other one leads to the login wall. */
    openToGuest?: true;
}

export interface ChromeAction {
    href: string;
    label: ChromeLabel;
    icon: Icon;
}

export type ChromeScope =
    | { kind: 'group'; id: number; name: string; imageUrl: string | null }
    | { kind: 'member'; id: number; name: string; imageUrl: string | null; avatarColor: string | null; isAi: boolean };

export interface Chrome {
    /**
     * section = hub header (h1 = nav label) from the registry; contextual = frame header with a
     * page-specific title; embedded = no frame header, the page body carrying its own heading.
     */
    mode: 'section' | 'contextual' | 'embedded';
    title?: ChromeLabel;
    tabs?: ChromeTab[];
    tabsLabel?: ChromeLabel;
    /** Rendered only for a signed-in member. */
    action?: ChromeAction;
    /** The page opens on a band of its own, so below desk width its h1 is heard and not seen. */
    deskHeading?: boolean;
    width: 'standard' | 'narrow';
    gap: '4' | '6' | '8';
    foreground?: boolean;
    context?: { href: string; label: string | ChromeLabel }[];
    /**
     * Absent on a form, whose bar must carry no link beside unsaved input, and on a page that is its
     * own subject.
     */
    scope?: ChromeScope;
    /**
     * A full-page edit / create / settings / confirmation screen, never an inline one
     * (docs/internals/feature-modules.md, "Surface responsibilities").
     */
    form?: boolean;
    /**
     * A screen whose whole job is writing one thing, and it implies `form`
     * (docs/internals/feature-modules.md, "Surface responsibilities"). `context` is still required:
     * it is the close control's cold-load fallback.
     */
    compose?: boolean;
    /**
     * A room the member stays in, reading and writing in the same place
     * (docs/internals/feature-modules.md, "Surface responsibilities").
     */
    conversation?: boolean;
}

/** False for a conversation too: there the shell stands the tabs itself, until the composer is engaged. */
export function hasBottomNav(chrome: Chrome): boolean {
    return !chrome.compose && !chrome.conversation;
}

export function chromeRecedes(chrome: Chrome): boolean {
    return !chrome.form && !chrome.conversation;
}

export interface NavSection {
    href: string;
    match: string[];
    icon: Icon;
    label: ChromeLabel;
    badge?: CountBadge;
    /** The unit that owns this section; absent for the ones an administrator cannot switch off. */
    feature?: FeatureKey;
}

const HOME = t('Home');
const WHATS_NEW = t("What's new");
const DIARIES = t('%Diaries%');
const COMMUNITIES = t('%Communities%');
const ACTIVITY = t('%Activity%');
const FRIENDS = t('%Friends%');
const MESSAGES = t('Messages');
const NOTIFICATIONS = t('Notifications');
const MEMBER_SEARCH = t('Search members');
const SETTINGS = t('Settings');
const HAPPENINGS = t('What happened');

export type PolicyKind = 'terms' | 'privacy';

export const POLICY_TITLES: Record<PolicyKind, ChromeLabel> = {
    terms: t('Terms of service'),
    privacy: t('Privacy policy'),
};

// The href is the joined list rather than the browse tab: only the joined rows show which group is
// waiting.
const GROUPS_SECTION: NavSection & { badge: CountBadge } = {
    href: '/groups/mine',
    match: ['/groups', '/topics', '/events'],
    icon: CONTENT_KINDS.group.icon,
    label: COMMUNITIES,
    badge: {
        count: 'groupTalks',
        label: t(':count %communities% with new talk messages'),
        one: t('1 %community% with new talk messages'),
    },
    feature: 'group',
};

/** Named because the bottom bar marks this tab alone: one entry, one phrase for the count. */
export const NOTIFICATIONS_SECTION: NavSection & { badge: CountBadge } = {
    href: '/notifications',
    match: ['/notifications'],
    icon: CONTENT_KINDS.notification.icon,
    label: NOTIFICATIONS,
    badge: { count: 'notifications', label: t(':count unread notifications'), one: t('1 unread notification') },
};

const MEMBER_SEARCH_SECTION: NavSection = {
    href: '/member/search',
    match: ['/member/search'],
    icon: Search,
    label: MEMBER_SEARCH,
};

// No unit owns either: with every unit switched off each still renders.
const WHATS_NEW_SECTION: NavSection = { href: '/dashboard', match: ['/dashboard'], icon: Rss, label: WHATS_NEW };
const HAPPENINGS_SECTION: NavSection = { href: '/home/latest', match: ['/home/'], icon: CONTENT_KINDS.issue.icon, label: HAPPENINGS };

/** Home is the brand row, so the nav leads with whichever of the two home pages `/` does not serve. */
const navSections = (home: ModernHomeId): NavSection[] => [home === 'whats_new' ? HAPPENINGS_SECTION : WHATS_NEW_SECTION, ...UNIT_SECTIONS];

const UNIT_SECTIONS: NavSection[] = [
    { href: '/diary/list', match: ['/diary'], icon: CONTENT_KINDS.diary.icon, label: DIARIES, feature: 'diary' },
    GROUPS_SECTION,
    { href: '/timeline', match: ['/timeline'], icon: CONTENT_KINDS.timeline.icon, label: ACTIVITY, feature: 'timeline' },
    {
        href: '/friend/list',
        match: ['/friend'],
        icon: CONTENT_KINDS.member.icon,
        label: FRIENDS,
        badge: { count: 'friendRequests', label: t(':count pending %friend% requests'), one: t('1 pending %friend% request') },
        feature: 'friend',
    },
    // The match is the bare `/message` prefix, which covers the conversation list and the mailbox
    // URLs alike.
    {
        href: '/messages',
        match: ['/message'],
        icon: CONTENT_KINDS.directMessage.icon,
        label: MESSAGES,
        badge: {
            count: 'unreadMessages',
            label: t(':count conversations with new messages'),
            one: t('1 conversation with new messages'),
        },
        feature: 'directMessage',
    },
    NOTIFICATIONS_SECTION,
    MEMBER_SEARCH_SECTION,
    { href: '/member/config', match: ['/member/config'], icon: Settings, label: SETTINGS },
];

export const NAV_SECTIONS: NavSection[] = navSections('issue');

/** Whether a path (query and hash already stripped) is inside a section. */
export function isSectionActive(section: NavSection, path: string): boolean {
    // The root is compared whole — as a prefix it would claim every path.
    return section.match.some((match) => (match === '/' ? path === '/' : path.startsWith(match)));
}

/** Talk has no section of its own, so the rooms hang under the entry whose badge they explain. */
export const TALK_ROOMS_HREF = '/groups/mine';

/** The nav an administrator's current toggles leave: a section whose unit is off answers 404. */
export function visibleNavSections(enabled: Record<FeatureKey, boolean>, home: ModernHomeId): NavSection[] {
    return navSections(home).filter((section) => section.feature === undefined || enabled[section.feature]);
}

/** Home is the brand row in the nav lists, so it exists only for the bottom bar, which has no brand. */
const HOME_SECTION: NavSection = { href: '/', match: ['/', '/home/'], icon: House, label: HOME };

/** The Home tab covers the page `/` serves under its other URL too, and leaves the nav's page alone. */
const homeSection = (home: ModernHomeId): NavSection => (home === 'whats_new' ? { ...HOME_SECTION, match: ['/', '/dashboard'] } : HOME_SECTION);

/**
 * The page `/` serves, plus the latest issue where `/` serves the other one. A dated issue is not one,
 * though its bar carries the brand too ({@link isIssueDay}).
 */
export function isHomeComponent(component: string, home: ModernHomeId): boolean {
    return component === 'home/issue' || (home === 'whats_new' && component === 'dashboard');
}

/** A day of できごと, latest or past: its band names the day and steps to the next, so nothing stands above it. */
export function isIssueDay(component: string): boolean {
    return component === 'home/issue' || component === 'home/archive';
}

/**
 * A deliberately fixed list rather than something an administrator picks, though a unit switched off
 * still drops its tab through visibleNavSections. Three plus Home, because a word under each icon is
 * what a phone seats without truncating the longest of them.
 */
const BOTTOM_NAV_HREFS = ['/groups/mine', '/diary/list', '/notifications'];

export function bottomNavSections(enabled: Record<FeatureKey, boolean>, home: ModernHomeId): NavSection[] {
    const visible = visibleNavSections(enabled, home);

    return [
        homeSection(home),
        ...BOTTOM_NAV_HREFS.map((href) => visible.find((section) => section.href === href)).filter((section) => section !== undefined),
    ];
}

const WRITE_DIARY: ChromeAction = { href: '/diary/new', label: t('Write a %diary%'), icon: Pencil };
const POST_ACTIVITY: ChromeAction = { href: '/timeline/new', label: t('%Post_activity%'), icon: Pencil };

/** The write action only while the site lets members post; a page that says nothing keeps it. */
function postActivityAction(props: Record<string, unknown>): { action?: ChromeAction } {
    return (props as { canPost?: boolean }).canPost === false ? {} : { action: POST_ACTIVITY };
}
const CREATE_COMMUNITY: ChromeAction = { href: '/groups/edit', label: t('Create a %community%'), icon: Plus };
const NEW_MESSAGE: ChromeAction = { href: '/messages/new', label: t('New message'), icon: Pencil };
const INVITE_MEMBER: ChromeAction = { href: '/invite', label: t('Invite a new member'), icon: UserPlus };

// The comment history is none of the hub's three lenses.
const diaryFeedTab = (variant: string): 'all' | 'friends' | null => (variant === 'commented' ? null : variant === 'friends' ? 'friends' : 'all');

// The friend tab is a lens the friend unit owns, so it goes with the unit while the diary hub stays.
const diaryTabs = (active: 'all' | 'friends' | 'mine' | null, friend: boolean): ChromeTab[] => [
    { href: '/diary/list', label: t('All'), active: active === 'all', openToGuest: true },
    ...(friend ? [{ href: '/diary/listFriend', label: FRIENDS, active: active === 'friends' }] : []),
    { href: '/diary/listMember', label: t('My %diaries%'), active: active === 'mine' },
];

// Joined leads because it is where the nav lands; each board tab goes with its unit while the hub stays.
const communityTabs = (active: 'browse' | 'joined' | 'topics' | 'events', units: { topic: boolean; event: boolean }): ChromeTab[] => [
    {
        href: '/groups/mine',
        label: t('Joined'),
        active: active === 'joined',
        // The nav badge itself, so the two announcements cannot drift apart.
        badge: GROUPS_SECTION.badge,
    },
    { href: '/groups', label: t('All'), active: active === 'browse' },
    ...(units.topic ? [{ href: '/topics', label: t('%Topics%'), active: active === 'topics' }] : []),
    ...(units.event ? [{ href: '/events', label: t('Events'), active: active === 'events' }] : []),
];

const boardUnits = (props: Record<string, unknown>) => ({ topic: enabled(props, 'groupTopic'), event: enabled(props, 'groupEvent') });

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

// The crumb is the one place the chrome shows the member's name, so titles stay generic and the
// name never renders twice back to back.
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

const MESSAGES_HUB: Chrome['context'] = [{ href: '/messages', label: MESSAGES }];

const CONFIG_CONTEXT: Chrome['context'] = [{ href: '/member/config', label: SETTINGS }];

interface OwnerScoped {
    owner: MemberRef;
    isOwner: boolean;
}

const homeOf = (props: Record<string, unknown>): ModernHomeId => (props as { modernHome?: ModernHomeId }).modernHome ?? 'issue';

// The shell shares the resolved unit map on every page (HandleInertiaRequests).
const enabled = (props: Record<string, unknown>, feature: FeatureKey): boolean =>
    (props as { enabledFeatures: Record<FeatureKey, boolean> }).enabledFeatures[feature];

const HUB_CHROME: Record<string, (props: Record<string, unknown>) => Partial<Chrome>> = {
    // As the home it takes the bare frame, the brand bar already naming the place, so the page
    // carries its own visually hidden h1.
    'dashboard': (props) => {
        const action = enabled(props, 'diary') ? WRITE_DIARY : undefined;
        return isHomeComponent('dashboard', homeOf(props)) ? { mode: 'embedded', action } : { mode: 'section', title: WHATS_NEW, action };
    },
    'home/issues': () => ({ mode: 'section', title: HAPPENINGS, deskHeading: true }),
    'policy/show': (props) => ({ mode: 'contextual', title: POLICY_TITLES[(props as { kind: PolicyKind }).kind], gap: '6' }),
    'diary/feed': (props) => ({
        mode: 'section',
        title: DIARIES,
        tabsLabel: DIARIES,
        tabs: diaryTabs(diaryFeedTab((props as { variant: string }).variant), enabled(props, 'friend')),
        action: WRITE_DIARY,
    }),
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
    'community/search': (props) => ({
        mode: 'section',
        title: COMMUNITIES,
        tabsLabel: COMMUNITIES,
        tabs: communityTabs('browse', boardUnits(props)),
        action: CREATE_COMMUNITY,
    }),
    // The group tabs are one hub: same h1 and create action on every tab, so switching tabs never
    // shifts the header.
    'community/list': (props) => {
        const { owner, isOwner } = props as unknown as OwnerScoped;
        return isOwner
            ? {
                  mode: 'section',
                  title: COMMUNITIES,
                  tabsLabel: COMMUNITIES,
                  tabs: communityTabs('joined', boardUnits(props)),
                  action: CREATE_COMMUNITY,
              }
            : { mode: 'contextual', title: COMMUNITIES, context: memberContext(owner), scope: memberScope(owner) };
    },
    'group/topic/recent': (props) => ({
        mode: 'section',
        title: COMMUNITIES,
        tabsLabel: COMMUNITIES,
        tabs: communityTabs('topics', boardUnits(props)),
        action: CREATE_COMMUNITY,
    }),
    'group/event/recent': (props) => ({
        mode: 'section',
        title: COMMUNITIES,
        tabsLabel: COMMUNITIES,
        tabs: communityTabs('events', boardUnits(props)),
        action: CREATE_COMMUNITY,
    }),
    // The dashboard digest's full list, not a hub tab: the set it shows is the digest's, mixed.
    'community/recent': (props) => ({
        mode: 'contextual',
        title: t('Recent %community% activity'),
        context: [{ href: homeOf(props) === 'whats_new' ? '/' : '/dashboard', label: WHATS_NEW }],
    }),
    // A board index keeps a short h1 so a long group name never wraps the heading.
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
    'community/edit': (props) => {
        const { group } = props as unknown as { group: CommunityRef | null };
        return group
            ? { form: true, context: communityContext(group) }
            : { form: true, context: [{ href: '/groups', label: COMMUNITIES }] };
    },
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
    'timeline/index': (props) => ({ mode: 'section', title: ACTIVITY, ...postActivityAction(props) }),
    'timeline/member': (props) => {
        const { owner, isOwner } = props as unknown as OwnerScoped;
        return isOwner
            ? { mode: 'section', title: ACTIVITY, ...postActivityAction(props) }
            : { mode: 'contextual', title: ACTIVITY, context: memberContext(owner), scope: memberScope(owner) };
    },
    'timeline/tag': (props) => ({
        mode: 'contextual',
        title: t('%Activity% posts tagged #:tag', { tag: (props as { tag: string }).tag }),
        context: [{ href: '/timeline', label: ACTIVITY }],
    }),
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
    'message/conversations/index': () => ({ mode: 'section', title: MESSAGES, action: NEW_MESSAGE }),
    // A withdrawn counterpart has no member page for a scope to link to and no name for the bar to
    // carry, so the room falls back to a heading.
    'message/conversation/index': (props) => {
        const { counterpart } = props as unknown as { counterpart: MemberRef | null };

        return {
            mode: 'contextual',
            title: counterpart ? MESSAGES : t('%Withdrawn_member%'),
            context: counterpart ? memberContext(counterpart) : MESSAGES_HUB,
            scope: counterpart ? memberScope(counterpart) : undefined,
            conversation: true,
        };
    },
    'member/search': (props) => ({
        mode: 'section',
        title: MEMBER_SEARCH,
        gap: '6',
        ...((props as { canInvite?: boolean }).canInvite ? { action: INVITE_MEMBER } : {}),
    }),
    'member/config': () => ({ mode: 'section', title: SETTINGS, gap: '8' }),
    'notifications/index': () => ({ mode: 'section', title: NOTIFICATIONS }),
};

const STATIC_CHROME: Record<string, Partial<Chrome>> = {
    'block/add': { width: 'narrow', form: true },
    'block/remove': { width: 'narrow', form: true },
    'friend/link': { width: 'narrow', form: true },
    'member/invite': { width: 'narrow', form: true, context: [{ href: '/member/search', label: MEMBER_SEARCH }] },
    'block/list': { gap: '6', context: CONFIG_CONTEXT },
    'member/avatar': { gap: '6', form: true, context: CONFIG_CONTEXT },
    'member/edit-profile': { gap: '6', form: true, context: CONFIG_CONTEXT },
    'member/show': { gap: '6' },
    'message/edit': { gap: '6', form: true, compose: true, context: MESSAGES_HUB },
    // Compose although it submits nothing: choosing who to write to is one screen with one job.
    'message/new': { gap: '6', form: true, compose: true, context: MESSAGES_HUB },
    'member/config/email': { gap: '6', form: true, context: CONFIG_CONTEXT },
    'member/config/password': { gap: '6', form: true, context: CONFIG_CONTEXT },
    'member/config/mfa': { gap: '6', form: true, context: CONFIG_CONTEXT },
    'member/config/passkeys': { gap: '6', form: true, context: CONFIG_CONTEXT },
    'member/config/notifications': { gap: '6', form: true, context: CONFIG_CONTEXT },
    'member/config/withdrawal': { gap: '6', form: true, context: CONFIG_CONTEXT },
    'member/config/ai/index': { gap: '6', form: true, context: CONFIG_CONTEXT },
    'member/config/ai/show': { gap: '6', form: true, context: CONFIG_CONTEXT },
    'group/show': { foreground: true },
    'group/topic/show': { foreground: true },
    'group/event/show': { foreground: true },
    'diary/show': { foreground: true },
    'diary/new': { form: true, compose: true, context: [{ href: '/diary/list', label: DIARIES }] },
    'timeline/show': { foreground: true },
    'timeline/new': { form: true, compose: true, context: [{ href: '/timeline', label: ACTIVITY }] },
};

/**
 * Modern components with intentionally no context crumb; ChromeContextCoverageTest fails a new page
 * that lands unclassified.
 */
export const NO_CONTEXT_COMPONENTS: readonly string[] = [
    'dashboard',
    // A day or a month of できごと is reached from its neighbours through its own band and foot.
    'home/issue',
    'home/archive',
    'home/issues',
    'diary/feed',
    'community/search',
    'group/topic/recent',
    'group/event/recent',
    'group/show',
    'timeline/index',
    'message/conversations/index',
    'member/search',
    'member/config',
    'notifications/index',
    'friend/requests',
    'member/show',
    'block/add',
    'block/remove',
    'friend/link',
    // Reached by a guest as well as a member, so there is no one parent to crumb back to.
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

/** A name is member text, so it is a plain string. */
interface Place {
    label: ChromeLabel | string;
    href: string;
}

interface PlaceRef {
    id: number;
    name: string;
}

const groupPlace = (group: PlaceRef): Place => ({ label: group.name, href: `/groups/${group.id}` });

const memberPlace = (member: PlaceRef): Place => ({ label: member.name, href: `/member/${member.id}` });

/** The pages that ARE a place, whose own hero names it directly below the header. */
const PLACE_TOPS = ['group/show', 'member/show'] as const;

export interface BreadcrumbCrumb {
    label: ChromeLabel | string;
    href: string;
    /** A crumb that is not pressable must not be painted as one. */
    link: boolean;
}

/** These pages speak the home grammar in the header: mark and site name, no crumb (docs/internals/modern-frame.md, "The phone header"). */
export function isPlaceTop(component: string): boolean {
    return (PLACE_TOPS as readonly string[]).includes(component);
}

/** The one trail target a guest may open: an author's entries list admits a guest whenever one of that author's entries does. */
const GUEST_OPEN = /^\/diary\/listMember\/\d+$/;

/**
 * A guest's crumb never takes the scope tier, whose target is a profile that may sit behind the
 * login wall, and a trail target not open to a guest is painted as text.
 */
function guestCrumb(chrome: Chrome): BreadcrumbCrumb | null {
    const last = chrome.context?.[chrome.context.length - 1];

    return last ? { label: last.label, href: last.href, link: chrome.form !== true && GUEST_OPEN.test(last.href) } : null;
}

/**
 * A screen inside no place answers its trail's last item and never home, since "you are at home" on
 * a settings page would be false. A form takes its trail rather than its scope: the scope tier is
 * pressable by construction, and the bar must never carry a link beside unsaved input.
 */
export function breadcrumbCrumb(chrome: Chrome, guest = false): BreadcrumbCrumb | null {
    if (guest) {
        return guestCrumb(chrome);
    }

    if (chrome.form !== true) {
        const { scope } = chrome;
        const place = scope?.kind === 'group' ? groupPlace(scope) : scope?.kind === 'member' ? memberPlace(scope) : null;
        if (place) {
            return { ...place, link: true };
        }
    }

    const last = chrome.context?.[chrome.context.length - 1];

    return last ? { label: last.label, href: last.href, link: chrome.form !== true } : null;
}
