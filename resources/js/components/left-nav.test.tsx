import { cleanup, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { LeftNav } from './left-nav';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
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

function arrive(auth: { user: AuthUser | null }) {
    inertia.page = {
        component: 'dashboard',
        url: '/dashboard',
        props: {
            name: 'Test SNS',
            snsLogo: { color: '#336699', url: null },
            auth,
            enabledFeatures: allOn,
            look: 'standard',
            unread: { friendRequests: 0, unreadMessages: 0, notifications: 0, groupTalks: 0 },
            talkNavRooms: null,
        },
    };
}

/** Named by the site name alone, because BrandMark is aria-hidden in both its arms. */
test.each([
    ['a member', user],
    ['a guest', null],
])('the brand takes %s to the front page', (_who, who) => {
    arrive({ user: who });

    renderWithProviders(<LeftNav />);

    expect(screen.getByRole('link', { name: 'Test SNS' }).getAttribute('href')).toBe('/');
});
