import { act, cleanup, fireEvent, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { TimelinePostCard } from './post-card';
import { fakeT } from '@/lib/test-i18n';
import { stubCoarsePointer } from '@/lib/test-pointer';
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

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    delete (navigator as { clipboard?: unknown }).clipboard;
});

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

test('the add button lives in the bar, joins the chips only once there are some, and delete is the author\'s alone', () => {
    const { container, rerender } = renderWithProviders(<TimelinePostCard post={post} viewerId={1} reactions={{ chips: [], vocabulary, onToggle: vi.fn(), onShowReactors: vi.fn(), reactorsUrl: '/x' }} />);

    expect(screen.getAllByRole('button', { name: 'Add a reaction' })).toHaveLength(1);
    expect(container.querySelector('[data-reactions]')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Delete' })).toBeNull();

    rerender(<TimelinePostCard post={post} viewerId={3} reactions={{ chips: [{ emoji: '\u{1F44D}', count: 1, mine: false }], vocabulary, onToggle: vi.fn(), onShowReactors: vi.fn(), reactorsUrl: '/x' }} />);
    const adds = screen.getAllByRole('button', { name: 'Add a reaction' });
    expect(adds).toHaveLength(1);
    expect(adds[0]?.closest('[data-reactions]')).not.toBeNull();
    expect(screen.queryByRole('button', { name: 'See who reacted' })).toBeNull();
    const remove = screen.getByRole('button', { name: 'Delete' });
    expect(remove.textContent).toBe('');
    expect(remove.closest('.absolute')).not.toBeNull();
});

test('a reader who may not react and did not write the post has no bar at all', () => {
    const { container } = renderWithProviders(<TimelinePostCard post={post} viewerId={1} reactions={{ chips: [], vocabulary }} />);

    expect(container.querySelectorAll('button')).toHaveLength(0);
});


test('a row with nothing the sheet could offer is not pressed, even with a page ready to open one', () => {
    vi.useFakeTimers();
    stubCoarsePointer();
    // A post always has an address, so without a clipboard to copy it to the sheet has nothing left.
    Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
    const onOpenActions = vi.fn();
    renderWithProviders(<TimelinePostCard post={{ ...post, body: '   ' }} viewerId={1} reactions={{ chips: [], vocabulary }} onOpenActions={onOpenActions} />);
    const row = screen.getByRole('listitem');

    fireEvent.pointerDown(row, { pointerType: 'touch', isPrimary: true, clientX: 10, clientY: 10 });
    act(() => {
        vi.advanceTimersByTime(600);
    });
    expect(onOpenActions).not.toHaveBeenCalled();
});
