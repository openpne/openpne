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
            unifiedLayout: false,
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

test('the shipped bar is five section tabs and names no place', () => {
    const chrome = arrive('dashboard', '/dashboard');

    render(<BottomNav chrome={chrome} />);

    expect(screen.getAllByRole('link').map((link) => link.getAttribute('href'))).toEqual([
        '/dashboard',
        '/groups/mine',
        '/diary/list',
        '/notifications',
        '/messages',
    ]);
    expect(document.querySelector('[data-dive-place]')).toBeNull();
});

/**
 * The unified row is three zones, and the middle one is the claim: this is where you are, and this is
 * the way back up to its top. A group's talk is inside the group, not inside the talk.
 */
test('the unified bar names the group a member is inside, from anywhere in it', () => {
    const chrome = arrive('group/talk/index', '/groups/7/talk', {
        unifiedLayout: true,
        group: { id: 7, name: 'Cyclists', imageUrl: null },
    });

    render(<BottomNav chrome={chrome} />);

    expect(placeLink().textContent).toBe('Cyclists');
    expect(placeLink().getAttribute('href')).toBe('/groups/7');
    // Not the page being read, so it is a way out rather than a "you are here".
    expect(placeLink().getAttribute('aria-current')).toBeNull();
    expect(screen.getByRole('link', { name: 'Search members' }).getAttribute('href')).toBe('/member/search');
    expect(screen.getByRole('link', { name: 'Notifications' }).getAttribute('href')).toBe('/notifications');
});

test("the group's own top is where the middle zone already stands", () => {
    const chrome = arrive('unified/group', '/groups/7', {
        unifiedLayout: true,
        group: { id: 7, name: 'Cyclists', imageUrl: null },
    });

    render(<BottomNav chrome={chrome} />);

    expect(placeLink().getAttribute('aria-current')).toBe('page');
});

test('a screen that is nowhere in particular falls back to home', () => {
    const chrome = arrive('notifications/index', '/notifications', { unifiedLayout: true });

    render(<BottomNav chrome={chrome} />);

    expect(placeLink().textContent).toBe('Home');
    expect(placeLink().getAttribute('href')).toBe('/dashboard');
});

test('the unified bar says how many notifications are waiting, in words', () => {
    const chrome = arrive('unified/home', '/dashboard', {
        unifiedLayout: true,
        unread: { friendRequests: 0, unreadMessages: 0, notifications: 2, groupTalks: 0 },
    });

    render(<BottomNav chrome={chrome} />);

    expect(screen.getByRole('link', { name: '2 unread notifications' }).getAttribute('href')).toBe('/notifications');
});

test('a guest gets no bar at all, whatever the layout', () => {
    const chrome = arrive('member/show', '/member/9', { unifiedLayout: true, auth: { user: null } });

    const { container } = render(<BottomNav chrome={chrome} />);

    expect(container.innerHTML).toBe('');
});
