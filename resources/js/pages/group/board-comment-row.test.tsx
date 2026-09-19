import { cleanup, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { BoardCommentRow } from './board-comment-row';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
import type { TopicComment } from '@/pages/community/types';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'en', timezone: 'Asia/Tokyo' } }),
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

afterEach(cleanup);

const comment: TopicComment = {
    id: 7,
    number: 2,
    body: 'count me in',
    images: [],
    linkCard: null,
    author: { id: 3, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false },
    createdAt: '2026-09-20T10:00:00+09:00',
    deletable: false,
    reactions: [{ emoji: '\u{1F44D}', count: 1, mine: false }],
};

test('a group member sees the chips under the body with a way to add one', () => {
    renderWithProviders(<BoardCommentRow comment={comment} onDelete={vi.fn()} reactions={{ chips: comment.reactions, vocabulary: ['\u{1F44D}'], onToggle: vi.fn(), onShowReactors: vi.fn() }} />);

    expect(screen.getByRole('button', { name: 'Add a reaction' })).toBeTruthy();
    expect(screen.getByRole('button', { name: /1/ })).toBeTruthy();
});

test('a reader who is not a member sees the counts and nothing to press', () => {
    renderWithProviders(<BoardCommentRow comment={comment} onDelete={vi.fn()} reactions={{ chips: comment.reactions, vocabulary: ['\u{1F44D}'] }} />);

    expect(screen.getByText('1')).toBeTruthy();
    expect(screen.queryAllByRole('button')).toHaveLength(0);
});

test('only a deletable comment offers the delete control', () => {
    renderWithProviders(<BoardCommentRow comment={{ ...comment, deletable: true }} onDelete={vi.fn()} reactions={{ chips: [], vocabulary: [] }} />);

    expect(screen.getByRole('button', { name: 'Delete' })).toBeTruthy();
});
