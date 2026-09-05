import { cleanup, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import TimelineShow from './show';
import type { TimelinePostEntry } from './types';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

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
    router: { visit: () => {}, post: () => {} },
    useForm: () => ({
        data: { body: '', mentions: [] },
        errors: {},
        processing: false,
        setData: () => {},
        transform: () => {},
        post: () => {},
        reset: () => {},
    }),
}));

afterEach(cleanup);

const post: TimelinePostEntry = {
    id: 7,
    body: 'already here',
    visibility: 'members',
    hasImages: false,
    replyCount: 0,
    images: [],
    mentions: [],
    tags: [],
    linkCard: null,
    author: { id: 3, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false },
    createdAt: '2026-09-05T12:00:00+09:00',
};

function renderShow(canPost: boolean) {
    inertia.page = { component: 'timeline/show', url: '/timeline/7', props: { post, replies: [], viewerId: 1, canPost } };

    return renderWithProviders(<TimelineShow />);
}

test('the reply form follows the posting switch', () => {
    renderShow(true);
    expect(screen.getByLabelText('Reply')).toBeTruthy();
    cleanup();

    renderShow(false);
    expect(screen.queryByLabelText('Reply')).toBeNull();
    expect(screen.getByText('already here')).toBeTruthy();
});
