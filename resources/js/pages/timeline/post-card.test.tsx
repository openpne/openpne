import { cleanup, fireEvent, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { TimelinePostCard } from './post-card';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
import type { TimelinePostEntry } from './types';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'en', timezone: 'Asia/Tokyo' } }),
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: { post: () => {} },
}));

afterEach(cleanup);

const post: TimelinePostEntry = {
    id: 7,
    body: 'a post',
    author: { id: 3, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false },
    visibility: 'members',
    hasImages: false,
    replyCount: 0,
    images: [],
    mentions: [],
    tags: [],
    linkCard: null,
    createdAt: '2026-09-19T10:00:00+09:00',
    reactions: [],
};

const vocabulary = ['\u{1F44D}', '\u{2764}\u{FE0F}'];

test('a chip is its own toggle and says whether it is held', () => {
    const onToggle = vi.fn();
    const chips = [
        { emoji: '\u{1F44D}', count: 2, mine: true },
        { emoji: '\u{2764}\u{FE0F}', count: 1, mine: false },
    ];
    renderWithProviders(<TimelinePostCard post={post} viewerId={1} reactions={{ chips, vocabulary, onToggle, onShowReactors: vi.fn() }} />);

    const held = screen.getByRole('button', { pressed: true });
    const theirs = screen.getByRole('button', { pressed: false });
    expect(held.textContent).toContain('2');
    fireEvent.click(held);
    expect(onToggle).toHaveBeenCalledWith('\u{1F44D}', true);

    fireEvent.click(theirs);
    expect(onToggle).toHaveBeenCalledWith('\u{2764}\u{FE0F}', false);
});

test('the add button is always on the card and the reactor list only beside chips', () => {
    const onShowReactors = vi.fn();
    const { rerender } = renderWithProviders(<TimelinePostCard post={post} viewerId={1} reactions={{ chips: [], vocabulary, onToggle: vi.fn(), onShowReactors }} />);

    expect(screen.getByRole('button', { name: 'Add a reaction' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'See who reacted' })).toBeNull();

    rerender(<TimelinePostCard post={post} viewerId={1} reactions={{ chips: [{ emoji: '\u{1F44D}', count: 1, mine: false }], vocabulary, onToggle: vi.fn(), onShowReactors }} />);
    fireEvent.click(screen.getByRole('button', { name: 'See who reacted' }));
    expect(onShowReactors).toHaveBeenCalled();
});
