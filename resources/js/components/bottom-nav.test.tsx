import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { BottomNav } from './bottom-nav';
import { fakeT } from '@/lib/test-i18n';
import { type Chrome, resolveChrome } from '@/lib/member-chrome';
import type { AuthUser, FeatureKey } from '@/types';

// useT reads the Inertia page for its term map, which a component test has no page to give it.
vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

const inertia = vi.hoisted(() => ({ page: {} as { component: string; url: string; props: Record<string, unknown> } }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => inertia.page,
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

afterEach(cleanup);

const allOn = Object.fromEntries(
    ['diary', 'directMessage', 'timeline', 'group', 'groupTopic', 'groupEvent', 'groupTalk', 'friend', 'mcp'].map((unit) => [unit, true]),
) as Record<FeatureKey, boolean>;

const user: AuthUser = { id: 1, name: 'Viewer', email: 'viewer@example.com', imageUrl: null, avatarColor: null, isAi: false };

function arrive(component: string, url: string, props: Record<string, unknown> = {}): Chrome {
    inertia.page = {
        component,
        url,
        props: {
            auth: { user },
            enabledFeatures: allOn,
            look: 'standard',
            unread: { friendRequests: 0, unreadMessages: 0, notifications: 0, groupTalks: 0 },
            ...props,
        },
    };

    return resolveChrome(component, inertia.page.props);
}

/** The middle zone's link: the place the bar says the member is in. */
const placeLink = (): HTMLElement => {
    const link = document.querySelector('[data-dive-place]');
    if (!(link instanceof HTMLElement)) {
        throw new Error('the unified bar drew no place');
    }

    return link;
};

test('the shipped bar is four labelled section tabs and names no place', () => {
    const chrome = arrive('dashboard', '/dashboard');

    render(<BottomNav chrome={chrome} />);

    expect(screen.getAllByRole('link').map((link) => link.getAttribute('href'))).toEqual([
        '/dashboard',
        '/groups/mine',
        '/diary/list',
        '/notifications',
    ]);
    // The word is on screen, not only in the accessible name an icon-only row would have to lean on.
    expect(screen.getByRole('link', { name: 'Home' }).textContent).toBe('Home');
    expect(document.querySelector('[data-dive-place]')).toBeNull();
});

test('the shipped row prints every count it carries, each said in words as well', () => {
    const chrome = arrive('dashboard', '/dashboard', {
        unread: { friendRequests: 4, unreadMessages: 5, notifications: 2, groupTalks: 3 },
    });

    render(<BottomNav chrome={chrome} />);

    // The phrase comes up through the pill rather than off the link itself, so the word under the
    // icon stays the tab's name and the number joins it — the shape the drawer's entries already
    // have. Exact names for this bar are pinned in count-pill.test.tsx; this match is on the phrase.
    
    const groups = screen.getByRole('link', { name: /3 %communities% with new messages/ });
    expect(groups.getAttribute('href')).toBe('/groups/mine');
    expect(groups.textContent).toContain('3');
    expect(groups.textContent).toContain('%Communities%');
    expect(screen.getByRole('link', { name: /2 unread notifications/ }).textContent).toContain('2');
    // The DM count is not on this row to print: it stays on the drawer entry that carries it.
    expect(screen.queryByRole('link', { name: /Messages/ })).toBeNull();
});

/**
 * The unified row is three zones, and the middle one is the claim: this is where you are, and this is
 * the way back up to its top. A group's talk is inside the group, not inside the talk.
 */
test('the unified bar names the group a member is inside, from anywhere in it', () => {
    const chrome = arrive('group/talk/index', '/groups/7/talk', {
        look: 'unified',
        group: { id: 7, name: 'Cyclists', imageUrl: null },
    });

    render(<BottomNav chrome={chrome} />);

    expect(placeLink().textContent).toBe('Cyclists');
    expect(placeLink().getAttribute('href')).toBe('/groups/7');
    // Not the page being read, so it is a way out rather than a "you are here".
    expect(placeLink().getAttribute('aria-current')).toBeNull();
    expect(screen.getByRole('link', { name: 'Search' }).getAttribute('href')).toBe('/member/search');
    expect(screen.getByRole('link', { name: 'Notifications' }).getAttribute('href')).toBe('/notifications');
});

test("the group's own top is where the middle zone already stands", () => {
    const chrome = arrive('unified/group', '/groups/7', {
        look: 'unified',
        group: { id: 7, name: 'Cyclists', imageUrl: null },
    });

    render(<BottomNav chrome={chrome} />);

    expect(placeLink().getAttribute('aria-current')).toBe('page');
});

test('a screen that is nowhere in particular falls back to home', () => {
    const chrome = arrive('notifications/index', '/notifications', { look: 'unified' });

    render(<BottomNav chrome={chrome} />);

    expect(placeLink().textContent).toBe('Home');
    expect(placeLink().getAttribute('href')).toBe('/dashboard');
});

test('the unified bar says how many notifications are waiting, in words', () => {
    const chrome = arrive('dashboard', '/dashboard', {
        look: 'unified',
        unread: { friendRequests: 0, unreadMessages: 0, notifications: 2, groupTalks: 0 },
    });

    render(<BottomNav chrome={chrome} />);

    expect(screen.getByRole('link', { name: '2 unread notifications' }).getAttribute('href')).toBe('/notifications');
});

test('the tabbed row marks the notification tab alone, and with a dot rather than a number', () => {
    const chrome = arrive('dashboard', '/dashboard', {
        look: 'tabbed',
        unread: { friendRequests: 4, unreadMessages: 5, notifications: 2, groupTalks: 3 },
    });

    const { container } = render(<BottomNav chrome={chrome} />);

    // A dot cannot print how many, so the count is said in words instead.
    expect(screen.getByRole('link', { name: '2 unread notifications' }).getAttribute('href')).toBe('/notifications');
    expect(container.textContent).not.toMatch(/\d/);
    // Every other tab is left unmarked under this look, its count kept by the drawer's pill.
    expect(screen.queryByRole('link', { name: '3 %communities% with new messages' })).toBeNull();
    expect(screen.getByRole('link', { name: '%Communities%' })).toBeTruthy();
    expect(screen.getByRole('link', { name: '%Diaries%' })).toBeTruthy();
});

test('a guest gets no bar at all, whatever the layout', () => {
    const chrome = arrive('member/show', '/member/9', { look: 'unified', auth: { user: null } });

    const { container } = render(<BottomNav chrome={chrome} />);

    expect(container.innerHTML).toBe('');
});
