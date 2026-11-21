import type { ComponentType } from 'react';
import { House, Pencil, Plus, Rss, Search, Settings, UserPlus } from 'lucide-react';
import { CONTENT_KINDS } from './content-kinds.ts';
import type { GroupOpenSections, GroupSection, MemberSection } from './place-sections.ts';
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
    | { kind: 'group'; id: number; name: string; imageUrl: string | null; section: GroupSection; open: GroupOpenSections }
    | { kind: 'member'; id: number; name: string; imageUrl: string | null; avatarColor: string | null; isAi: boolean; section: MemberSection | null };

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
 * The page `/` serves, plus the latest issue where `/` serves the other one. A past day's issue is not
 * one: it is a page of the section, as its month is.
 */
export function isHomeComponent(component: string, home: ModernHomeId): boolean {
    return component === 'home/issue' || (home === 'whats_new' && component === 'dashboard');
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

const CLOSED_BOARDS: GroupOpenSections = { talk: false, topic: false, event: false };

/** A page that does not say which boards are open offers none of them. */
const groupScope = (props: Record<string, unknown>, section: GroupSection): ChromeScope => {
    const { group, openSections } = props as { group: CommunityRef; openSections?: GroupOpenSections };

    return { kind: 'group', id: group.id, name: group.name, imageUrl: group.imageUrl, section, open: openSections ?? CLOSED_BOARDS };
};

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

// The header is the one place the chrome shows the member's name, so titles stay generic and the
// name never renders twice back to back.
const memberScope = (member: MemberRef, section: MemberSection | null): ChromeScope => ({
    kind: 'member',
    id: member.id,
    name: member.name,
    imageUrl: member.imageUrl,
    avatarColor: member.avatarColor,
    isAi: member.isAi,
    section,
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
    // As the home it takes the bare frame, the header already naming the site, so the page
    // carries its own visually hidden h1.
    'dashboard': (props) => {
        const action = enabled(props, 'diary') ? WRITE_DIARY : undefined;
        return isHomeComponent('dashboard', homeOf(props)) ? { mode: 'embedded', action } : { mode: 'section', title: WHATS_NEW, action };
    },
    'home/issues': () => ({ mode: 'section', title: HAPPENINGS }),
    'home/archive': () => ({ mode: 'section', title: HAPPENINGS }),
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
            : { mode: 'contextual', title: DIARIES, scope: memberScope(owner, 'diary') };
    },
    'diary/show': (props) => {
        const { diary } = props as unknown as { diary: { author: MemberRef } };
        return { scope: memberScope(diary.author, 'diary') };
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
            : { mode: 'contextual', title: COMMUNITIES, scope: memberScope(owner, 'groups') };
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
            scope: groupScope(props, 'topic'),
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
            scope: groupScope(props, 'event'),
            action: canPost
                ? { href: `/groups/${group.id}/events/new`, label: t('Create an event'), icon: Plus }
                : undefined,
        };
    },
    // The conversation is the page, so no action button: its composer is always on screen.
    'group/talk/index': (props) => ({ mode: 'contextual', title: t('Talk'), scope: groupScope(props, 'talk'), conversation: true }),
    'group/topic/show': (props) => ({ scope: groupScope(props, 'topic') }),
    'group/event/show': (props) => ({ scope: groupScope(props, 'event') }),
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
    'community/pending': (props) => ({ mode: 'contextual', title: t('Pending members'), scope: groupScope(props, 'members') }),
    'community/edit': (props) => {
        const { group } = props as unknown as { group: CommunityRef | null };
        return group
            ? { form: true, context: communityContext(group) }
            : { form: true, context: [{ href: '/groups', label: COMMUNITIES }] };
    },
    'community/members': (props) => ({ mode: 'contextual', title: t('Members'), scope: groupScope(props, 'members') }),
    'community/manage': (props) => ({ mode: 'contextual', title: t('Management member'), scope: groupScope(props, 'members') }),
    'group/event/members': (props) => ({ mode: 'contextual', title: t('Count of Member'), scope: groupScope(props, 'event') }),
    'timeline/index': (props) => ({ mode: 'section', title: ACTIVITY, ...postActivityAction(props) }),
    'timeline/member': (props) => {
        const { owner, isOwner } = props as unknown as OwnerScoped;
        return isOwner
            ? { mode: 'section', title: ACTIVITY, ...postActivityAction(props) }
            : { mode: 'contextual', title: ACTIVITY, scope: memberScope(owner, 'timeline') };
    },
    'timeline/tag': (props) => ({
        mode: 'contextual',
        title: t('%Activity% posts tagged #:tag', { tag: (props as { tag: string }).tag }),
        context: [{ href: '/timeline', label: ACTIVITY }],
    }),
    'timeline/show': (props) => {
        const { post } = props as unknown as { post: { author: MemberRef } };
        return { scope: memberScope(post.author, 'timeline') };
    },
    'friend/list': (props) => {
        const { owner, isOwner } = props as unknown as OwnerScoped;
        return isOwner
            ? { mode: 'section', title: FRIENDS, tabsLabel: FRIENDS, tabs: friendTabs('list') }
            : { mode: 'contextual', title: FRIENDS, scope: memberScope(owner, 'friends') };
    },
    'friend/requests': () => ({
        mode: 'section',
        title: FRIENDS,
        tabsLabel: FRIENDS,
        tabs: friendTabs('requests'),
    }),
    'message/conversations/index': () => ({ mode: 'section', title: MESSAGES, action: NEW_MESSAGE }),
    // A withdrawn counterpart has no place to open and no name for the bar to carry, so the room
    // falls back to a heading.
    'message/conversation/index': (props) => {
        const { counterpart } = props as unknown as { counterpart: MemberRef | null };

        return {
            mode: 'contextual',
            title: counterpart ? MESSAGES : t('%Withdrawn_member%'),
            context: counterpart ? undefined : MESSAGES_HUB,
            scope: counterpart ? memberScope(counterpart, null) : undefined,
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
 * Modern components with intentionally no context; ChromeContextCoverageTest fails a new page that
 * lands unclassified.
 */
export const NO_CONTEXT_COMPONENTS: readonly string[] = [
    'dashboard',
    // The issue pages, a day or a month, are reached from their neighbours through their own band and foot.
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
    // Reached by a guest as well as a member, so it stands in no one part of the site.
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

/** The pages that ARE a place, whose own hero names it directly below the header. */
const PLACE_TOPS = ['group/show', 'member/show'] as const;

export function isPlaceTop(component: string): boolean {
    return (PLACE_TOPS as readonly string[]).includes(component);
}

/**
 * A guest's `place` is not pressable, since their sections may sit behind the login wall. A `hub`
 * title is the screen's own h1 again and so is not read aloud (docs/internals/modern-frame.md, "The phone header").
 */
export type Header =
    | { type: 'brand' }
    | { type: 'place'; scope: ChromeScope; pressable: boolean }
    | { type: 'title'; label: ChromeLabel | string; hub: boolean }
    | { type: 'none' };

export interface HeaderPage {
    component: string;
    home: ModernHomeId;
    guest: boolean;
}

/**
 * A screen inside no place answers the last item of its context rather than home, since "you are at
 * home" on a settings page would be false. A form takes its context rather than its scope, so no
 * sections open beside unsaved input.
 */
export function headerOf(chrome: Chrome, { component, home, guest }: HeaderPage): Header {
    if (isHomeComponent(component, home)) {
        return { type: 'brand' };
    }

    // Left empty rather than given a hub's name, as the hero below names the place (docs/internals/modern-frame.md, "The phone header").
    if (isPlaceTop(component)) {
        return { type: 'none' };
    }

    if (chrome.scope && chrome.form !== true) {
        return { type: 'place', scope: chrome.scope, pressable: !guest };
    }

    if (chrome.mode === 'section' && chrome.title) {
        return { type: 'title', label: chrome.title, hub: true };
    }

    const last = chrome.context?.[chrome.context.length - 1];

    return last ? { type: 'title', label: last.label, hub: false } : { type: 'none' };
}

/** The desk place bar stands wherever `headerOf` names something other than the site, a compose sheet included though its phone header is its own (docs/internals/modern-frame.md, "Desk width"). */
export function hasPlaceBar(header: Header): header is Extract<Header, { type: 'place' | 'title' }> {
    return header.type === 'place' || header.type === 'title';
}
