import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { fakeT } from '@/lib/test-i18n';
import type { AuthUser, FeatureKey } from '@/types';
import UnifiedMember from './member';

// useT reads the Inertia page for its term map, which a component test has no page to give it.
vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

const inertia = vi.hoisted(() => ({ page: {} as { props: Record<string, unknown> } }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => inertia.page,
    Head: () => null,
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

const viewer: AuthUser = { id: 1, name: 'Viewer', email: 'viewer@example.com', imageUrl: null, avatarColor: null, isAi: false };

function open(user: AuthUser | null, profile: Record<string, unknown> = {}) {
    inertia.page = {
        props: {
            auth: { user },
            enabledFeatures: allOn,
            profile: {
                id: 9,
                name: 'Owner',
                avatarUrl: null,
                avatarUrlLarge: null,
                avatarColor: null,
                isAi: false,
                bio: 'the first line\nthe second\nthe third\nthe fourth',
                age: null,
                isSelf: false,
                friendStatus: 'none',
                ...profile,
            },
            fields: [],
            groups: [],
            friends: [],
            recentPhotos: [],
            recentDiaries: [],
        },
    };

    return render(<UnifiedMember />);
}

/** The fields list leaves the self-introduction out, so this heading is the one place it is read. */
test.each([['a member', viewer], ['a guest', null]] as const)('%s reads the self-introduction whole, line breaks kept', (_who, user) => {
    const { container } = open(user);

    const bio = screen.getByText(/the first line/);
    expect(bio.textContent).toContain('the fourth');
    expect(bio.closest('p')?.className).toContain('whitespace-pre-wrap');
    expect(container.querySelector('.line-clamp-2')).toBeNull();
});

/** A guest's units arrive switched off, so the units are on here: the viewer alone decides it. */
test('a guest is offered no way to a member-only screen', () => {
    open(null);

    expect(screen.queryAllByRole('link')).toHaveLength(0);
});

test('a member is offered the friend request and the message', () => {
    open(viewer);

    expect(screen.getAllByRole('link').map((link) => link.getAttribute('href'))).toEqual(['/friend/link?id=9', '/messages/9']);
});
