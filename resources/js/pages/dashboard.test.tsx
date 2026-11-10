import { cleanup, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import Dashboard from './dashboard';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
import type { AuthUser, FeatureKey } from '@/types';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

const inertia = vi.hoisted(() => ({ page: {} as { component: string; url: string; props: Record<string, unknown> } }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => inertia.page,
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    Head: () => null,
    router: { visit: () => {} },
}));

afterEach(cleanup);

const allOn = Object.fromEntries(
    ['diary', 'directMessage', 'timeline', 'group', 'groupTopic', 'groupEvent', 'groupTalk', 'friend', 'mcp'].map((unit) => [unit, true]),
) as Record<FeatureKey, boolean>;

const user: AuthUser = { id: 1, name: 'Viewer', email: 'viewer@example.com', imageUrl: null, avatarColor: null, isAi: false };

const diary = {
    id: 7,
    title: 'Morning walk',
    excerpt: '',
    visibility: 'members',
    commentCount: 0,
    hasImages: false,
    thumbnails: [],
    author: { id: 3, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false },
    createdAt: '2026-08-27T09:00:00+09:00',
};

const arrive = (props: Record<string, unknown>) => {
    inertia.page = {
        component: 'dashboard',
        url: '/dashboard',
        props: {
            auth: { user },
            enabledFeatures: allOn,
            announcements: { friendRequests: 0, unreadMessages: 0, groupApprovals: [], adminTransfers: [] },
            newcomer: false,
            friendDiaries: [],
            diaries: [],
            commentedDiaries: [],
            myDiaries: [],
            groupActivity: [],
            timeline: [],
            ...props,
        },
    };
    renderWithProviders(<Dashboard />);
};

const EMPTY = 'There are no new posts.';

test('a long-standing member with nothing in any section is told so', () => {
    arrive({});

    expect(screen.getByText(EMPTY)).toBeTruthy();
    expect(screen.queryByText('Search members')).toBeNull();
});

test('one section with rows is enough to drop the line', () => {
    arrive({ commentedDiaries: [diary] });

    expect(screen.queryByText(EMPTY)).toBeNull();
    expect(screen.getByText('Morning walk')).toBeTruthy();
});

test('a newcomer gets the welcome panel in place of the line', () => {
    arrive({ newcomer: true });

    expect(screen.queryByText(EMPTY)).toBeNull();
    expect(screen.getByText('Search members')).toBeTruthy();
});
