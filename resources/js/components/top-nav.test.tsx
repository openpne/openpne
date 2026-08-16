import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { ScopeIdentity, TopNav } from './top-nav';
import { fakeT } from '@/lib/test-i18n';
import { type Chrome, type ChromeScope, resolveChrome } from '@/lib/member-chrome';
import type { AuthUser, FeatureKey } from '@/types';

// useT reads the Inertia page for its term map, which a component test has no page to give it.
vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

// The bar reads the whole Inertia page (which component, which URL, the shared props) and links with
// the router's Link; a test has neither, so both are stood in for. `Link` stays an anchor so the
// bar's controls keep the roles and names the assertions below are about.
const inertia = vi.hoisted(() => ({ page: {} as { component: string; url: string; props: Record<string, unknown> } }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => inertia.page,
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: { visit: () => {} },
}));

afterEach(cleanup);

const memberScope: ChromeScope = { kind: 'member', id: 7, name: 'Shirabe', imageUrl: null, avatarColor: null, isAi: false };

/**
 * The bar's scope is the member the page is *about* — a diary's author, a DM counterparty — not the
 * viewer, so it can be an AI account. Its avatar is decorative and there is no room beside a
 * truncating name for a chip, which leaves the link's accessible name as the only place the fact fits.
 */
test('an AI scope is named as one', () => {
    render(<ScopeIdentity scope={{ ...memberScope, isAi: true }} />);

    expect(screen.getByRole('link', { name: 'Shirabe (AI)' })).toBeTruthy();
});

test("a person's scope is named by their name alone", () => {
    render(<ScopeIdentity scope={memberScope} />);

    expect(screen.getByRole('link', { name: 'Shirabe' })).toBeTruthy();
});

test('a group scope is named by the group, unmarked', () => {
    render(<ScopeIdentity scope={{ kind: 'group', id: 3, name: 'Book club', imageUrl: null }} />);

    expect(screen.getByRole('link', { name: 'Book club' })).toBeTruthy();
});

test('the scope block still links to whoever it names', () => {
    render(<ScopeIdentity scope={{ ...memberScope, isAi: true }} />);

    expect(screen.getByRole('link').getAttribute('href')).toBe('/member/7');
});

const allOn = Object.fromEntries(
    ['diary', 'directMessage', 'timeline', 'group', 'groupTopic', 'groupEvent', 'groupTalk', 'friend', 'mcp'].map((unit) => [unit, true]),
) as Record<FeatureKey, boolean>;

const user: AuthUser = { id: 1, name: 'Viewer', email: 'viewer@example.com', imageUrl: null, avatarColor: null, isAi: false };

/** One page for the bar to read: which screen it is on, and the shared props it draws from. */
function arrive(component: string, url: string, props: Record<string, unknown> = {}): Chrome {
    inertia.page = {
        component,
        url,
        props: {
            name: 'Test SNS',
            snsLogo: { color: '#336699', url: null },
            auth: { user },
            enabledFeatures: allOn,
            unifiedLayout: false,
            unread: { friendRequests: 0, unreadMessages: 0, notifications: 0, groupTalks: 0 },
            ...props,
        },
    };

    return resolveChrome(component, inertia.page.props);
}

test('the shipped home keeps the brand bar', () => {
    const chrome = arrive('dashboard', '/dashboard');

    render(<TopNav chrome={chrome} />);

    expect(screen.getByText('Test SNS')).toBeTruthy();
    expect(screen.queryByRole('link', { name: '%Communities%' })).toBeNull();
});

test('the unified layout puts the two places in the bar instead of the brand', () => {
    const chrome = arrive('unified/home', '/dashboard', { unifiedLayout: true });

    render(<TopNav chrome={chrome} />);

    // The brand is what the tab pair replaces: it named a page the member was already on.
    expect(screen.queryByText('Test SNS')).toBeNull();
    expect(screen.getByRole('link', { name: 'Home' }).getAttribute('href')).toBe('/dashboard');
    expect(screen.getByRole('link', { name: '%Communities%' }).getAttribute('href')).toBe('/groups/mine');
    // Only the one the member is standing on is the current page.
    expect(screen.getByRole('link', { name: 'Home' }).getAttribute('aria-current')).toBe('page');
    expect(screen.getByRole('link', { name: '%Communities%' }).getAttribute('aria-current')).toBeNull();
});

test('a hub in the unified layout takes the same bar, with the group tab current', () => {
    const chrome = arrive('community/list', '/groups/mine', { unifiedLayout: true, owner: user, isOwner: true });

    render(<TopNav chrome={chrome} />);

    expect(screen.getByRole('link', { name: '%Communities%' }).getAttribute('aria-current')).toBe('page');
    expect(screen.getByRole('link', { name: 'Home' }).getAttribute('aria-current')).toBeNull();
});

test('the group tab goes with its unit', () => {
    const chrome = arrive('unified/home', '/dashboard', { unifiedLayout: true, enabledFeatures: { ...allOn, group: false } });

    render(<TopNav chrome={chrome} />);

    expect(screen.queryByRole('link', { name: '%Communities%' })).toBeNull();
    expect(screen.getByRole('link', { name: 'Home' })).toBeTruthy();
});

test('the bell says how many are waiting, in words', () => {
    const chrome = arrive('unified/home', '/dashboard', {
        unifiedLayout: true,
        unread: { friendRequests: 0, unreadMessages: 0, notifications: 3, groupTalks: 0 },
    });

    render(<TopNav chrome={chrome} />);

    const bell = screen.getByRole('link', { name: '3 unread notifications' });
    expect(bell.getAttribute('href')).toBe('/notifications');
});

test('the bell is named plainly while nothing is waiting', () => {
    const chrome = arrive('unified/home', '/dashboard', { unifiedLayout: true });

    render(<TopNav chrome={chrome} />);

    expect(screen.getByRole('link', { name: 'Notifications' }).getAttribute('href')).toBe('/notifications');
});

/**
 * The mock covers the top level only. A page below it says where the reader is, which a tab pair
 * claiming they are at the top level would contradict — so the deep bars are the same either way.
 */
test('a page below the top level keeps its own bar with the switch on', () => {
    const chrome = arrive('group/topic/show', '/topics/3', {
        unifiedLayout: true,
        group: { id: 7, name: 'Cyclists', imageUrl: null },
    });

    render(<TopNav chrome={chrome} />);

    expect(screen.getByRole('link', { name: 'Cyclists' }).getAttribute('href')).toBe('/groups/7');
    expect(screen.queryByRole('link', { name: 'Home' })).toBeNull();
});

test("a guest's bar is the same with the switch on", () => {
    // The switch never reaches a guest (HandleInertiaRequests), and the bar does not read it either:
    // a signed-out visitor has no member nav to carry.
    const chrome = arrive('member/show', '/member/9', { unifiedLayout: true, auth: { user: null } });

    render(<TopNav chrome={chrome} />);

    expect(screen.getByText('Test SNS')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Log In' }).getAttribute('href')).toBe('/login');
});

/**
 * The design's bar ends at the bell: no account control (the drawer took it in), and the bell wears
 * a dot rather than a printed number — how many is the link's name and the notification screen's
 * answer, not the bar's.
 */
test('the unified bar carries no account menu and prints no count', () => {
    const chrome = arrive('unified/home', '/dashboard', {
        unifiedLayout: true,
        unread: { friendRequests: 0, unreadMessages: 0, notifications: 3, groupTalks: 0 },
    });

    const { container } = render(<TopNav chrome={chrome} />);

    expect(screen.queryByRole('button', { name: 'Account menu' })).toBeNull();
    expect(container.textContent).not.toContain('3');
});
