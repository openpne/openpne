import { act, cleanup, fireEvent, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import DiaryShow from './show';
import type { DiaryComment, DiaryDetail, DiaryThread } from './types';
import { fakeT } from '@/lib/test-i18n';
import { stubCoarsePointer } from '@/lib/test-pointer';
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
    useForm: () => ({ data: { body: '', images: [] }, errors: {}, processing: false, setData: () => {}, post: () => {}, reset: () => {} }),
}));

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

const diary: DiaryDetail = {
    id: 5,
    title: 'An entry',
    excerpt: 'words',
    body: 'words',
    format: 'plain',
    bodyHtml: null,
    visibility: 'open',
    commentCount: 1,
    hasImages: false,
    thumbnails: [],
    images: [],
    linkCard: null,
    author: { id: 3, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false },
    createdAt: '2026-09-19T10:00:00+09:00',
    reactions: [{ emoji: '\u{1F44D}', count: 2, mine: false }],
};

const comment: DiaryComment = {
    id: 7,
    number: 1,
    body: 'a comment',
    images: [],
    linkCard: null,
    author: { id: 4, name: 'Aoi', imageUrl: null, avatarColor: null, isAi: false },
    createdAt: '2026-09-19T10:05:00+09:00',
    deletable: false,
    reactions: [{ emoji: '\u{1F44D}', count: 1, mine: false }],
};

const thread: DiaryThread = { comments: [comment], total: 1, size: 20, page: 1, lastPage: 1, ascending: true, hasOlder: false, hasNewer: false, olderPage: null, newerPage: null };

function renderShow(user: { id: number } | null) {
    inertia.page = {
        component: 'diary/show',
        url: '/diary/5',
        props: { diary, thread, older: null, newer: null, auth: { user }, rowActionsHint: user === null ? null : 'shown', reactionVocabulary: ['\u{1F44D}'], renderGeneration: 'g1', locale: 'en', timezone: 'Asia/Tokyo', imageUpload: { accept: 'image/png' } },
    };

    return renderWithProviders(<DiaryShow />);
}

test('a signed-in reader may react to the entry and to each comment', () => {
    renderShow({ id: 9 });

    // The entry's own, and the end of the comment's chips; the comment's bar offers none once it has chips.
    expect(screen.getAllByRole('button', { name: 'Add a reaction' })).toHaveLength(2);
    expect(screen.queryByRole('button', { name: 'See who reacted' })).toBeNull();
});

test('a guest on a web-public entry reads the counts and has nothing to press', () => {
    const { container } = renderShow(null);

    expect(container.querySelectorAll('[data-reactions]')).toHaveLength(2);
    expect(screen.queryByRole('button', { name: 'Add a reaction' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'See who reacted' })).toBeNull();
    expect(screen.getByText('2')).toBeTruthy();
});

test('a guest is offered no way to the names: the chips are counts and the held comment\'s sheet carries no reactor item', () => {
    vi.useFakeTimers();
    stubCoarsePointer();
    const { container } = renderShow(null);
    expect(container.querySelectorAll('[data-reactions] button')).toHaveLength(0);

    fireEvent.pointerDown(screen.getByRole('listitem'), { pointerType: 'touch', isPrimary: true, clientX: 10, clientY: 10 });
    act(() => {
        vi.advanceTimersByTime(600);
    });
    expect(screen.getByRole('dialog', { name: 'Post actions' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Select text' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'See who reacted' })).toBeNull();
});

test('the hint stands above the comments until a comment is held, and is written off then', () => {
    vi.useFakeTimers();
    stubCoarsePointer();
    const fetch = vi.fn(() => new Promise<Response>(() => {}));
    vi.stubGlobal('fetch', fetch);
    renderShow({ id: 9 });
    expect(screen.getByText('Hold a row to react and more.')).toBeTruthy();

    fireEvent.pointerDown(screen.getByRole('listitem'), { pointerType: 'touch', isPrimary: true, clientX: 10, clientY: 10 });
    act(() => {
        vi.advanceTimersByTime(600);
    });

    expect(screen.queryByText('Hold a row to react and more.')).toBeNull();
    expect(fetch).toHaveBeenCalledWith('/member/config/row-actions-hint', expect.objectContaining({ method: 'POST' }));

    cleanup();
    renderShow(null);
    expect(screen.queryByText('Hold a row to react and more.')).toBeNull();
});
