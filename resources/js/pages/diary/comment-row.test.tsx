import { act, cleanup, fireEvent, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { DiaryCommentRow } from './comment-row';
import { fakeT } from '@/lib/test-i18n';
import { stubCoarsePointer } from '@/lib/test-pointer';
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

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

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

    // At the end of the chips alone; the bar offers it only on a row with none.
    expect(screen.getAllByRole('button', { name: 'Add a reaction' })).toHaveLength(1);
    expect(screen.getByRole('button', { name: /1/ })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'See who reacted' })).toBeNull();
});

test('a guest on a web-public entry sees the counts and nothing to press', () => {
    renderWithProviders(<DiaryCommentRow comment={comment} onDelete={vi.fn()} reactions={{ chips: comment.reactions, vocabulary: ['\u{1F44D}'] }} />);

    expect(screen.getByText('1')).toBeTruthy();
    expect(screen.queryAllByRole('button')).toHaveLength(0);
});

test('only a deletable comment offers the delete control, as an icon in the bar rather than a link in the flow', () => {
    renderWithProviders(<DiaryCommentRow comment={{ ...comment, deletable: true }} onDelete={vi.fn()} reactions={{ chips: [], vocabulary: ['\u{1F44D}'], onToggle: vi.fn() }} />);

    const remove = screen.getByRole('button', { name: 'Delete' });
    expect(remove.textContent).toBe('');
    expect(remove.closest('.absolute')).not.toBeNull();
    expect(screen.getAllByRole('button', { name: 'Add a reaction' })).toHaveLength(1);
});

test('a finger held on the row hands the row to the page; a press on a chip does not', () => {
    vi.useFakeTimers();
    stubCoarsePointer();
    const onOpenActions = vi.fn();
    const onShowReactors = vi.fn();
    renderWithProviders(<DiaryCommentRow comment={comment} onDelete={vi.fn()} reactions={{ chips: comment.reactions, vocabulary: ['\u{1F44D}'], onToggle: vi.fn(), onShowReactors, reactorsUrl: '/x' }} onOpenActions={onOpenActions} />);
    const row = screen.getByRole('listitem');

    fireEvent.pointerDown(screen.getByRole('button', { name: /1/ }), { pointerType: 'touch', isPrimary: true, clientX: 10, clientY: 10 });
    act(() => {
        vi.advanceTimersByTime(600);
    });
    expect(onShowReactors).toHaveBeenCalledWith('\u{1F44D}');
    expect(onOpenActions).not.toHaveBeenCalled();

    fireEvent.pointerDown(row, { pointerType: 'touch', isPrimary: true, clientX: 10, clientY: 10 });
    act(() => {
        vi.advanceTimersByTime(600);
    });
    expect(onOpenActions).toHaveBeenCalledWith(row);
});
