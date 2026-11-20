import { cleanup, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { TopNav } from './top-nav';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
import { type Chrome, resolveChrome } from '@/lib/member-chrome';
import type { AuthUser, FeatureKey } from '@/types';

// useT reads the Inertia page for its term map, which a component test has no page to give it.
vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

// The bar reads the whole Inertia page and links with the router's Link, so both are stood in for;
// `Link` stays an anchor to keep the roles the assertions below are about.
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
            unread: { friendRequests: 0, unreadMessages: 0, notifications: 0, groupTalks: 0 },
            ...props,
        },
    };

    return resolveChrome(component, inertia.page.props);
}

/** What's new is a hub, so the bar carries its title beside the mark. */
test("the bar carries the What's new title", () => {
    const chrome = arrive('dashboard', '/dashboard');

    const { container } = renderWithProviders(<TopNav chrome={chrome} />);

    expect(container.textContent?.includes("What's new")).toBe(true);
});

test("a guest's place top is the home grammar, ending in the way in rather than the menu", () => {
    const chrome = arrive('member/show', '/member/9', { auth: { user: null }, profile: { id: 9, name: 'Owner' } });

    renderWithProviders(<TopNav chrome={chrome} />);

    expect(screen.getByRole('link', { name: 'Test SNS' }).getAttribute('href')).toBe('/');
    expect(screen.getByRole('link', { name: 'Log In' }).getAttribute('href')).toBe('/login');
    expect(screen.queryByRole('button', { name: 'Menu' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'Owner' })).toBeNull();
});

test("a guest's entry page crumbs to the author's entries, never to the author's page", () => {
    const author = { id: 9, name: 'Owner', imageUrl: null, avatarColor: null, isAi: false };
    const chrome = arrive('diary/show', '/diary/3', { auth: { user: null }, diary: { author } });

    renderWithProviders(<TopNav chrome={chrome} />);

    expect(screen.getByRole('link', { name: "Owner's %diary%" }).getAttribute('href')).toBe('/diary/listMember/9');
    expect(document.querySelector('a[href="/member/9"]')).toBeNull();
    expect(screen.getByRole('link', { name: 'Log In' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Menu' })).toBeNull();
});

test("a guest's crumb to a page behind the login wall is text", () => {
    const owner = { id: 9, name: 'Owner', imageUrl: null, avatarColor: null, isAi: false };
    const chrome = arrive('diary/list', '/diary/listMember/9', { auth: { user: null }, owner, isOwner: false });

    const { container } = renderWithProviders(<TopNav chrome={chrome} />);

    expect(container.textContent).toContain('Owner');
    expect(screen.queryByRole('link', { name: 'Owner' })).toBeNull();
});

/**
 * The bar speaks one grammar on every screen class: the site mark, then where you are. Home
 * is the root spelled out — mark plus name, nothing after it — and the bar carries no bell, no
 * account control and no tab pair, the four labelled tabs below having taken the moving about.
 */
test('the home bar is the site mark and its name, and nothing else', () => {
    const chrome = arrive('home/issue', '/', {
        unread: { friendRequests: 0, unreadMessages: 0, notifications: 3, groupTalks: 0 },
    });

    renderWithProviders(<TopNav chrome={chrome} />);

    expect(screen.getByRole('link', { name: 'Test SNS' }).getAttribute('href')).toBe('/');
    // And named by that alone — a word spelled over a name the reader can see says something else.
    expect(screen.queryByRole('link', { name: 'Home' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'Notifications' })).toBeNull();
    expect(screen.queryByRole('link', { name: '3 unread notifications' })).toBeNull();
    expect(screen.queryByRole('link', { name: '%Communities%' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Account menu' })).toBeNull();
    // The way to the rest of the nav, and it says so in words.
    expect(screen.getByRole('button', { name: 'Menu' })).toBeTruthy();
});

test('a past day of できごと takes the same bar as the latest, with no crumb above it', () => {
    const chrome = arrive('home/archive', '/home/2026/08/20', { issue: { monthHref: '/home/2026/08' } });

    renderWithProviders(<TopNav chrome={chrome} />);

    expect(screen.getByRole('link', { name: 'Test SNS' }).getAttribute('href')).toBe('/');
    expect(screen.queryByRole('link', { name: 'What happened' })).toBeNull();
});

test('a month of できごと is the mark and the page it is, with no crumb above it', () => {
    const chrome = arrive('home/issues', '/home/2026/08');

    const { container } = renderWithProviders(<TopNav chrome={chrome} />);

    expect(screen.getByRole('link', { name: 'Home' }).getAttribute('href')).toBe('/');
    expect(container.textContent).toContain('What happened');
    expect(screen.queryByRole('link', { name: 'What happened' })).toBeNull();
});

test('a hub is the mark and the section it stands on', () => {
    const chrome = arrive('community/list', '/groups/mine', { owner: user, isOwner: true });

    const { container } = renderWithProviders(<TopNav chrome={chrome} />);

    // The mark alone, so it is named rather than left to a site name that is not beside it.
    expect(screen.getByRole('link', { name: 'Home' }).getAttribute('href')).toBe('/');
    expect(screen.queryByText('Test SNS')).toBeNull();
    expect(container.textContent).toContain('%Communities%');
    // The section title is a label, not a way to somewhere: the hub is the page being read.
    expect(screen.queryByRole('link', { name: '%Communities%' })).toBeNull();
});

test('a deep page carries the place it is inside, as something to press', () => {
    const chrome = arrive('group/topic/show', '/topics/3', {
        group: { id: 7, name: 'Cyclists', imageUrl: null },
    });

    renderWithProviders(<TopNav chrome={chrome} />);

    expect(screen.getByRole('link', { name: 'Home' }).getAttribute('href')).toBe('/');
    expect(screen.getByRole('link', { name: 'Cyclists' }).getAttribute('href')).toBe('/groups/7');
    // No back control: swipe, the browser and the mark are the ways out.
    expect(screen.queryByRole('link', { name: 'Back' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Back' })).toBeNull();
});

test('a place top speaks the home grammar — no crumb over its own hero', () => {
    // The three-page symmetry extends to the header: home, a member's page and a group's share one
    // bar, and the place's hero names it directly below where a pill would have repeated it.
    const chrome = arrive('group/show', '/groups/7', {
        group: { id: 7, name: 'Cyclists', imageUrl: null },
    });

    renderWithProviders(<TopNav chrome={chrome} />);

    expect(screen.getByText('Test SNS')).toBeTruthy();
    expect(screen.queryByRole('link', { name: 'Cyclists' })).toBeNull();
    // The brand link reads by the visible site name, not a doubled "Home" label.
    expect(screen.queryByRole('link', { name: 'Home' })).toBeNull();
});

test('a form names where it sits without offering a way out of itself', () => {
    const chrome = arrive('member/config/email', '/member/config/email');

    const { container } = renderWithProviders(<TopNav chrome={chrome} />);

    expect(container.textContent).toContain('Settings');
    // The invariant the pill must not reverse: no link stands beside an unsaved form.
    expect(screen.queryByRole('link', { name: 'Settings' })).toBeNull();
    expect(screen.getAllByRole('link').map((link) => link.getAttribute('href'))).toEqual(['/']);
});

test('a page that is nowhere leaves the mark standing alone', () => {
    const chrome = arrive('policy/show', '/terms');

    const { container } = renderWithProviders(<TopNav chrome={chrome} />);

    expect(screen.getAllByRole('link').map((link) => link.getAttribute('href'))).toEqual(['/']);
    expect(container.textContent).not.toContain('›');
    // BrandMark is aria-hidden in both its arms, so a mark standing alone has whatever name is put
    // on the link and no other.
    expect(screen.getByRole('link', { name: 'Home' })).toBeTruthy();
    expect(screen.queryByRole('link', { name: 'Test SNS' })).toBeNull();
});

/** A sheet is a mode rather than a screen class, and it is left by its own ✕. */
test('a compose screen keeps the sheet header', () => {
    const chrome = arrive('diary/new', '/diary/new');

    renderWithProviders(<TopNav chrome={chrome} />);

    expect(screen.getByRole('link', { name: 'Close' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Menu' })).toBeNull();
});
