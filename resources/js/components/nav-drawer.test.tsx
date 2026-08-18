import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { NavDrawer } from './nav-drawer';
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
    router: { visit: () => {}, post: () => {} },
}));

afterEach(cleanup);

const allOn = Object.fromEntries(
    ['diary', 'directMessage', 'timeline', 'group', 'groupTopic', 'groupEvent', 'groupTalk', 'friend', 'mcp'].map((unit) => [unit, true]),
) as Record<FeatureKey, boolean>;

const user: AuthUser = { id: 1, name: 'Viewer', email: 'viewer@example.com', imageUrl: null, avatarColor: null, isAi: false };

function arrive(look: string) {
    inertia.page = {
        component: 'dashboard',
        url: '/dashboard',
        props: {
            name: 'Test SNS',
            snsLogo: { color: '#336699', url: null },
            auth: { user },
            enabledFeatures: allOn,
            look,
            unread: { friendRequests: 0, unreadMessages: 0, notifications: 0, groupTalks: 0 },
        },
    };
}

function openDrawer(labeled = false) {
    render(<NavDrawer labeled={labeled} />);
    fireEvent.click(screen.getByRole('button', { name: 'Menu' }));
}

/**
 * Wiring, not values (the app-shell test's sibling): whether the drawer carries the account rows is
 * `accountInDrawer`'s question alone, and the looks whose bars drop the avatar menu must answer it
 * with rows here — read from the field, not inferred from another look-shaped boolean.
 */
test.each([
    ['standard', false],
    ['unified', true],
    ['tabbed', true],
] as const)('%s answers the account rows from its own field', (look, rows) => {
    arrive(look);
    openDrawer();

    expect(screen.queryByRole('link', { name: 'Profile' }) !== null).toBe(rows);
    expect(screen.queryByRole('button', { name: 'Sign out' }) !== null).toBe(rows);
});

test('the labeled trigger opens the sheet from its own side, close control staying at the right', () => {
    arrive('tabbed');
    openDrawer(true);

    // The trigger names itself with the visible word, so no aria-label doubles it.
    const sheet = screen.getByRole('dialog');
    expect(sheet.className).toContain('right-0');
    expect(sheet.className).not.toContain('left-0');
    // The survey invariant is "the ✕ sits on the trigger's side", not a mirror of the left sheet.
    expect(screen.getByRole('button', { name: 'Close' }).className).toContain('right-3');
});
