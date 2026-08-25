import { cleanup, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { ScopeIdentity, TopNav } from './top-nav';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
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
    renderWithProviders(<ScopeIdentity scope={{ ...memberScope, isAi: true }} />);

    expect(screen.getByRole('link', { name: 'Shirabe (AI)' })).toBeTruthy();
});

test("a person's scope is named by their name alone", () => {
    renderWithProviders(<ScopeIdentity scope={memberScope} />);

    expect(screen.getByRole('link', { name: 'Shirabe' })).toBeTruthy();
});

test('a group scope is named by the group, unmarked', () => {
    renderWithProviders(<ScopeIdentity scope={{ kind: 'group', id: 3, name: 'Book club', imageUrl: null }} />);

    expect(screen.getByRole('link', { name: 'Book club' })).toBeTruthy();
});

test('the scope block still links to whoever it names', () => {
    renderWithProviders(<ScopeIdentity scope={{ ...memberScope, isAi: true }} />);

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
            look: 'standard',
            unread: { friendRequests: 0, unreadMessages: 0, notifications: 0, groupTalks: 0 },
            ...props,
        },
    };

    return resolveChrome(component, inertia.page.props);
}

test('the shipped home keeps the brand bar', () => {
    const chrome = arrive('dashboard', '/dashboard');

    renderWithProviders(<TopNav chrome={chrome} />);

    expect(screen.getByText('Test SNS')).toBeTruthy();
    expect(screen.queryByRole('link', { name: '%Communities%' })).toBeNull();
});

test('the unified layout puts the two places in the bar instead of the brand', () => {
    const chrome = arrive('unified/home', '/dashboard', { look: 'unified' });

    renderWithProviders(<TopNav chrome={chrome} />);

    // The brand is what the tab pair replaces: it named a page the member was already on.
    expect(screen.queryByText('Test SNS')).toBeNull();
    expect(screen.getByRole('link', { name: 'Home' }).getAttribute('href')).toBe('/dashboard');
    expect(screen.getByRole('link', { name: '%Communities%' }).getAttribute('href')).toBe('/groups/mine');
    // Only the one the member is standing on is the current page.
    expect(screen.getByRole('link', { name: 'Home' }).getAttribute('aria-current')).toBe('page');
    expect(screen.getByRole('link', { name: '%Communities%' }).getAttribute('aria-current')).toBeNull();
});

test('a hub in the unified layout takes the same bar, with the group tab current', () => {
    const chrome = arrive('community/list', '/groups/mine', { look: 'unified', owner: user, isOwner: true });

    renderWithProviders(<TopNav chrome={chrome} />);

    expect(screen.getByRole('link', { name: '%Communities%' }).getAttribute('aria-current')).toBe('page');
    expect(screen.getByRole('link', { name: 'Home' }).getAttribute('aria-current')).toBeNull();
});

test('the group tab goes with its unit', () => {
    const chrome = arrive('unified/home', '/dashboard', { look: 'unified', enabledFeatures: { ...allOn, group: false } });

    renderWithProviders(<TopNav chrome={chrome} />);

    expect(screen.queryByRole('link', { name: '%Communities%' })).toBeNull();
    expect(screen.getByRole('link', { name: 'Home' })).toBeTruthy();
});

test('the bell says how many are waiting, in words', () => {
    const chrome = arrive('unified/home', '/dashboard', {
        look: 'unified',
        unread: { friendRequests: 0, unreadMessages: 0, notifications: 3, groupTalks: 0 },
    });

    renderWithProviders(<TopNav chrome={chrome} />);

    const bell = screen.getByRole('link', { name: '3 unread notifications' });
    expect(bell.getAttribute('href')).toBe('/notifications');
});

test('the bell is named plainly while nothing is waiting', () => {
    const chrome = arrive('unified/home', '/dashboard', { look: 'unified' });

    renderWithProviders(<TopNav chrome={chrome} />);

    expect(screen.getByRole('link', { name: 'Notifications' }).getAttribute('href')).toBe('/notifications');
});

/**
 * The mock covers the top level only. A page below it says where the reader is, which a tab pair
 * claiming they are at the top level would contradict — so the deep bars are the same either way.
 */
test('a page below the top level keeps its own bar with the switch on', () => {
    const chrome = arrive('group/topic/show', '/topics/3', {
        look: 'unified',
        group: { id: 7, name: 'Cyclists', imageUrl: null },
    });

    renderWithProviders(<TopNav chrome={chrome} />);

    expect(screen.getByRole('link', { name: 'Cyclists' }).getAttribute('href')).toBe('/groups/7');
    expect(screen.queryByRole('link', { name: 'Home' })).toBeNull();
});

test("a guest's bar is the same with the switch on", () => {
    // The switch never reaches a guest (HandleInertiaRequests), and the bar does not read it either:
    // a signed-out visitor has no member nav to carry.
    const chrome = arrive('member/show', '/member/9', { look: 'unified', auth: { user: null } });

    renderWithProviders(<TopNav chrome={chrome} />);

    expect(screen.getByText('Test SNS')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Log In' }).getAttribute('href')).toBe('/login');
});

/**
 * The tabbed look speaks one grammar on every screen class: the site mark, then where you are. Home
 * is the root spelled out — mark plus name, nothing after it — and the bar carries no bell, no
 * account control and no tab pair, the four labelled tabs below having taken the moving about.
 */
test('the tabbed home is the site mark and its name, and nothing else', () => {
    const chrome = arrive('unified/home', '/dashboard', {
        look: 'tabbed',
        unread: { friendRequests: 0, unreadMessages: 0, notifications: 3, groupTalks: 0 },
    });

    renderWithProviders(<TopNav chrome={chrome} />);

    expect(screen.getByRole('link', { name: 'Test SNS' }).getAttribute('href')).toBe('/dashboard');
    expect(screen.queryByRole('link', { name: 'Notifications' })).toBeNull();
    expect(screen.queryByRole('link', { name: '3 unread notifications' })).toBeNull();
    expect(screen.queryByRole('link', { name: '%Communities%' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Account menu' })).toBeNull();
    // The way to the rest of the nav, and it says so in words.
    expect(screen.getByRole('button', { name: 'Menu' })).toBeTruthy();
});

test('a tabbed hub is the mark and the section it stands on', () => {
    const chrome = arrive('community/list', '/groups/mine', { look: 'tabbed', owner: user, isOwner: true });

    const { container } = renderWithProviders(<TopNav chrome={chrome} />);

    // The mark alone, so it is named rather than left to a site name that is not beside it.
    expect(screen.getByRole('link', { name: 'Home' }).getAttribute('href')).toBe('/dashboard');
    expect(screen.queryByText('Test SNS')).toBeNull();
    expect(container.textContent).toContain('%Communities%');
    // The section title is a label, not a way to somewhere: the hub is the page being read.
    expect(screen.queryByRole('link', { name: '%Communities%' })).toBeNull();
});

test('a tabbed deep page carries the place it is inside, as something to press', () => {
    const chrome = arrive('group/topic/show', '/topics/3', {
        look: 'tabbed',
        group: { id: 7, name: 'Cyclists', imageUrl: null },
    });

    renderWithProviders(<TopNav chrome={chrome} />);

    expect(screen.getByRole('link', { name: 'Home' }).getAttribute('href')).toBe('/dashboard');
    expect(screen.getByRole('link', { name: 'Cyclists' }).getAttribute('href')).toBe('/groups/7');
    // No back control: swipe, the browser and the mark are the ways out under this look.
    expect(screen.queryByRole('link', { name: 'Back' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Back' })).toBeNull();
});

test('a tabbed place top speaks the home grammar — no crumb over its own hero', () => {
    // The three-page symmetry extends to the header: home, a member's page and a group's share one
    // bar, and the place's hero names it directly below where a pill would have repeated it.
    const chrome = arrive('unified/group', '/groups/7', {
        look: 'tabbed',
        group: { id: 7, name: 'Cyclists', imageUrl: null },
    });

    renderWithProviders(<TopNav chrome={chrome} />);

    expect(screen.getByText('Test SNS')).toBeTruthy();
    expect(screen.queryByRole('link', { name: 'Cyclists' })).toBeNull();
    // The brand link reads by the visible site name, not a doubled "Home" label.
    expect(screen.queryByRole('link', { name: 'Home' })).toBeNull();
});

test('a tabbed form names where it sits without offering a way out of itself', () => {
    const chrome = arrive('member/config/email', '/member/config/email', { look: 'tabbed' });

    const { container } = renderWithProviders(<TopNav chrome={chrome} />);

    expect(container.textContent).toContain('Settings');
    // The invariant the pill must not reverse: no link stands beside an unsaved form.
    expect(screen.queryByRole('link', { name: 'Settings' })).toBeNull();
    expect(screen.getAllByRole('link').map((link) => link.getAttribute('href'))).toEqual(['/dashboard']);
});

test('a tabbed page that is nowhere leaves the mark standing alone', () => {
    const chrome = arrive('block/list', '/block/list', { look: 'tabbed' });

    const { container } = renderWithProviders(<TopNav chrome={chrome} />);

    expect(screen.getAllByRole('link').map((link) => link.getAttribute('href'))).toEqual(['/dashboard']);
    expect(container.textContent).not.toContain('›');
});

/** A sheet is a mode rather than a screen class, and it is left by its own ✕. */
test('a tabbed compose screen keeps the sheet header', () => {
    const chrome = arrive('diary/new', '/diary/new', { look: 'tabbed' });

    renderWithProviders(<TopNav chrome={chrome} />);

    expect(screen.getByRole('link', { name: 'Close' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Menu' })).toBeNull();
});

/**
 * The design's bar ends at the bell: no account control (the drawer took it in), and the bell wears
 * a dot rather than a printed number — how many is the link's name and the notification screen's
 * answer, not the bar's.
 */
test('the unified bar carries no account menu and prints no count', () => {
    const chrome = arrive('unified/home', '/dashboard', {
        look: 'unified',
        unread: { friendRequests: 0, unreadMessages: 0, notifications: 3, groupTalks: 0 },
    });

    const { container } = renderWithProviders(<TopNav chrome={chrome} />);

    expect(screen.queryByRole('button', { name: 'Account menu' })).toBeNull();
    expect(container.textContent).not.toContain('3');
});
