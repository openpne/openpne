import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { BottomNav } from './bottom-nav';
import { fakeT } from '@/lib/test-i18n';
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

function arrive(component: string, url: string, props: Record<string, unknown> = {}): void {
    inertia.page = {
        component,
        url,
        props: {
            auth: { user },
            enabledFeatures: allOn,
            unread: { friendRequests: 0, unreadMessages: 0, notifications: 0, groupTalks: 0 },
            ...props,
        },
    };
}

test('the bar is four labelled section tabs', () => {
    arrive('home/issue', '/');

    render(<BottomNav />);

    expect(screen.getAllByRole('link').map((link) => link.getAttribute('href'))).toEqual([
        '/',
        '/groups/mine',
        '/diary/list',
        '/notifications',
    ]);
    // The word is on screen, not only in the accessible name an icon-only row would have to lean on.
    expect(screen.getByRole('link', { name: 'Home' }).textContent).toBe('Home');
    expect(screen.getByRole('link', { name: 'Home' }).getAttribute('aria-current')).toBe('page');
});

/** The issues are under home, so reading one keeps the tab the reader arrived through lit. */
test('a dated issue still stands on the Home tab', () => {
    arrive('home/archive', '/home/2026/08/20');

    render(<BottomNav />);

    expect(screen.getByRole('link', { name: 'Home' }).getAttribute('aria-current')).toBe('page');
});

test('the row marks the notification tab alone, and with a dot rather than a number', () => {
    arrive('dashboard', '/dashboard', {
        unread: { friendRequests: 4, unreadMessages: 5, notifications: 2, groupTalks: 3 },
    });

    const { container } = render(<BottomNav />);

    // A dot cannot print how many, so the count is said in words, after the word as everywhere.
    expect(screen.getByRole('link', { name: 'Notifications 2 unread notifications' }).getAttribute('href')).toBe('/notifications');
    expect(container.querySelector('.rounded-full.bg-primary')).toBeNull();
    // Every other tab is left unmarked, its count kept by the drawer's pill.
    expect(screen.queryByRole('link', { name: '3 %communities% with new talk messages' })).toBeNull();
    expect(screen.getByRole('link', { name: '%Communities%' })).toBeTruthy();
    expect(screen.getByRole('link', { name: '%Diaries%' })).toBeTruthy();
});

test('a guest gets no bar at all', () => {
    arrive('member/show', '/member/9', { auth: { user: null } });

    const { container } = render(<BottomNav />);

    expect(container.innerHTML).toBe('');
});
