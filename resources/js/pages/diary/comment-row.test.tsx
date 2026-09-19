import { cleanup, fireEvent, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { DiaryCommentRow } from './comment-row';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
import type { DiaryComment } from './types';

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

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));
const openMenu = () => fireEvent.keyDown(screen.getByRole('button', { name: 'More actions' }), { key: 'Enter' });

const comment: DiaryComment = {
    id: 7,
    number: 2,
    body: 'Thanks for writing this',
    images: [],
    linkCard: null,
    author: { id: 3, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false },
    createdAt: '2026-09-19T10:00:00+09:00',
    deletable: false,
    reactions: [{ emoji: '\u{1F44D}', count: 1, mine: false }],
};

test('a member sees the chips under the body with a way to add one', () => {
    renderWithProviders(<DiaryCommentRow comment={comment} onDelete={vi.fn()} reactions={{ chips: comment.reactions, vocabulary: ['\u{1F44D}'], onToggle: vi.fn(), onShowReactors: vi.fn() }} />);

    expect(screen.getByRole('button', { name: 'Add a reaction' })).toBeTruthy();
    expect(screen.getByRole('button', { name: /1/ })).toBeTruthy();
});

test('a guest on a web-public entry sees the counts and nothing to press', () => {
    renderWithProviders(<DiaryCommentRow comment={comment} onDelete={vi.fn()} reactions={{ chips: comment.reactions, vocabulary: ['\u{1F44D}'] }} />);

    expect(screen.getByText('1')).toBeTruthy();
    expect(screen.queryAllByRole('button')).toHaveLength(0);
});

test('only a deletable comment offers delete, in the menu, and it names the comment', async () => {
    const onDelete = vi.fn();
    renderWithProviders(<DiaryCommentRow comment={{ ...comment, deletable: true }} onDelete={onDelete} reactions={{ chips: [], vocabulary: [] }} />);
    openMenu();
    fireEvent.click(screen.getByRole('menuitem', { name: 'Delete' }));
    await tick();
    expect(onDelete).toHaveBeenCalledWith(7);

    cleanup();
    renderWithProviders(<DiaryCommentRow comment={comment} onDelete={vi.fn()} reactions={{ chips: [], vocabulary: [] }} />);
    expect(screen.queryByRole('button', { name: 'More actions' })).toBeNull();
});
