import { cleanup, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { NavItems } from './nav-items';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
import type { FeatureKey, ModernGroupListId, TalkNavRooms } from '@/types';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

const inertia = vi.hoisted(() => ({ page: {} as { url: string; props: Record<string, unknown> } }));

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

// Ids 1 and 12 on purpose: one is a string prefix of the other.
const rooms: TalkNavRooms = {
    rooms: [
        { id: 1, name: 'Book club', imageUrl: null, unread: 0, muted: false },
        { id: 12, name: 'Tennis', imageUrl: null, unread: 0, muted: false },
    ],
    hasMore: false,
};

function arrive(url: string, groupList: ModernGroupListId) {
    inertia.page = {
        url,
        props: {
            enabledFeatures: allOn,
            modernHome: 'issue',
            groupList,
            unread: { friendRequests: 0, unreadMessages: 0, notifications: 0, groupTalks: 0 },
        },
    };
    renderWithProviders(<NavItems rooms={rooms} />);
}

const row = (name: string) => screen.getByRole('link', { name });

test('a sidebar room opens the talk where the site says rows open the talk', () => {
    arrive('/groups/12/talk', 'talk');

    expect(row('Book club').getAttribute('href')).toBe('/groups/1/talk');
    expect(row('Tennis').getAttribute('href')).toBe('/groups/12/talk');
    expect(row('Tennis').getAttribute('aria-current')).toBe('page');
    expect(row('Book club').getAttribute('aria-current')).toBeNull();
});

test('a sidebar room opens the group top where the site says rows open the group', () => {
    arrive('/dashboard', 'group');

    expect(row('Book club').getAttribute('href')).toBe('/groups/1');
    expect(row('Tennis').getAttribute('href')).toBe('/groups/12');
});

test.each(['/groups/12', '/groups/12/topics', '/groups/12?page=2'])('only the group at %s is current, not the one whose id is its prefix', (url) => {
    arrive(url, 'group');

    expect(row('Tennis').getAttribute('aria-current')).toBe('page');
    expect(row('Book club').getAttribute('aria-current')).toBeNull();
});
