import { act, cleanup, fireEvent, screen } from '@testing-library/react';
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

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

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
    reactions: [],
};

function renderShow(canPost: boolean, over: Record<string, unknown> = {}) {
    inertia.page = { component: 'timeline/show', url: '/timeline/7', props: { post, replies: [], viewerId: 1, canPost, reactionVocabulary: ['\u{1F44D}'], renderGeneration: 'g1', ...over } };

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

test('a fresh render of the same thread shows the rows it was rendered with, not an earlier answer', async () => {
    // The page keeps its state across a reply post (Inertia preserves it on POST), so only a value
    // the server changes per render can tell a fresh render from a re-render of the same post.
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ reactions: [{ emoji: '\u{1F44D}', count: 1, mine: true }] }) } as Response)));
    const { rerender } = renderShow(true);

    fireEvent.click(screen.getByRole('button', { name: 'Add a reaction' }));
    await act(async () => {
        const thumb = screen.getAllByRole('button', { pressed: false }).find((b) => b.textContent === '\u{1F44D}');
        if (thumb === undefined) throw new Error('the picker did not offer the thumbs up');
        fireEvent.click(thumb);
    });
    expect(screen.getByRole('button', { pressed: true }).textContent).toContain('1');

    inertia.page = { ...inertia.page, props: { ...inertia.page.props, post: { ...post, reactions: [{ emoji: '\u{1F44D}', count: 2, mine: true }] }, renderGeneration: 'g2' } };
    rerender(<TimelineShow />);

    expect(screen.getByRole('button', { pressed: true }).textContent).toContain('2');
});

test('a reaction on the post itself is not the hint followed; one on a reply is', () => {
    const fetch = vi.fn(() => new Promise<Response>(() => {}));
    vi.stubGlobal('fetch', fetch);
    const reply = { ...post, id: 8, reactions: [{ emoji: '\u{1F44D}', count: 1, mine: false }] };
    renderShow(true, { replies: [reply], rowActionsHint: 'shown', post: { ...post, reactions: [{ emoji: '\u{1F44D}', count: 2, mine: false }] } });
    expect(screen.getByText('Hover to react and more.')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: '\u{1F44D} 2' }));
    expect(screen.getByText('Hover to react and more.')).toBeTruthy();
    expect(fetch).not.toHaveBeenCalledWith('/member/config/row-actions-hint', expect.anything());

    fireEvent.click(screen.getByRole('button', { name: '\u{1F44D} 1' }));
    expect(screen.queryByText('Hover to react and more.')).toBeNull();
    expect(fetch).toHaveBeenCalledWith('/member/config/row-actions-hint', expect.objectContaining({ method: 'POST' }));
});
