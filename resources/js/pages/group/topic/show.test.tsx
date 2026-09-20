import { act, cleanup, fireEvent, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import GroupTopicShow from './show';
import type { TopicDetail, TopicThread } from '@/pages/community/types';
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
    useForm: () => ({ data: { body: '', images: [] }, errors: {}, processing: false, setData: () => {}, transform: () => {}, post: () => {}, reset: () => {} }),
}));

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

const topic: TopicDetail = {
    id: 11,
    name: 'A topic',
    body: 'words',
    format: 'plain',
    bodyHtml: null,
    images: [],
    linkCard: null,
    author: { id: 3, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false },
    createdAt: '2026-09-19T10:00:00+09:00',
    editedAt: null,
    reactions: [{ emoji: '\u{1F44D}', count: 2, mine: false }],
};

const comment = { id: 21, number: 1, body: 'a comment', images: [], linkCard: null, author: { id: 4, name: 'Aoi', imageUrl: null, avatarColor: null, isAi: false }, createdAt: '2026-09-19T10:05:00+09:00', deletable: false, reactions: [{ emoji: '\u{1F44D}', count: 1, mine: false }] };

const thread: TopicThread = { comments: [], total: 0, page: 1, lastPage: 1, ascending: true, hasOlder: false, hasNewer: false, olderPage: null, newerPage: null };

function renderShow(canComment: boolean, reactions = topic.reactions, comments: TopicThread['comments'] = [], hint: 'shown' | 'dismissed' = 'dismissed') {
    inertia.page = {
        component: 'group/topic/show',
        url: '/topics/11',
        props: {
            group: { id: 2, name: 'A group', description: '', memberCount: 1, imageUrl: null, category: null },
            topic: { ...topic, reactions },
            thread: { ...thread, comments, total: comments.length },
            canComment,
            canEdit: false,
            reactionVocabulary: ['\u{1F44D}'],
            renderGeneration: 'g1',
            auth: { user: { id: 3 } },
            rowActionsHint: hint,
            locale: 'en',
            timezone: 'Asia/Tokyo',
            imageUpload: { accept: 'image/png' },
        },
    };

    return renderWithProviders(<GroupTopicShow />);
}

test('a member reacts to the body on its own endpoint', () => {
    const fetch = vi.fn(() => new Promise<Response>(() => {}));
    vi.stubGlobal('fetch', fetch);
    renderShow(true);

    expect(screen.getAllByRole('button', { name: 'Add a reaction' })).toHaveLength(1);
    fireEvent.click(screen.getByRole('button', { name: '\u{1F44D} 2' }));
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(fetch).toHaveBeenCalledWith('/topics/11/reactions', expect.objectContaining({ method: 'POST' }));
});

test('a non-member reading an open board sees the counts and has nothing to press', () => {
    const { container } = renderShow(false);

    expect(container.querySelectorAll('[data-reactions]')).toHaveLength(1);
    expect(screen.queryByRole('button', { name: 'Add a reaction' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'See who reacted' })).toBeNull();
    expect(screen.getByText('2')).toBeTruthy();
});

test('a non-member\'s held comment raises a sheet without the reactor item', () => {
    vi.useFakeTimers();
    stubCoarsePointer();
    renderShow(false, topic.reactions, [comment]);

    fireEvent.pointerDown(screen.getByRole('listitem'), { pointerType: 'touch', isPrimary: true, clientX: 10, clientY: 10 });
    act(() => {
        vi.advanceTimersByTime(600);
    });
    expect(screen.getByRole('button', { name: 'Select text' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'See who reacted' })).toBeNull();
});

test('the body keeps its add button with no reactions at all', () => {
    renderShow(true, []);

    expect(screen.getAllByRole('button', { name: 'Add a reaction' })).toHaveLength(1);
});

test('the names behind the body\'s chip are read from the body route', () => {
    const fetch = vi.fn(() => new Promise<Response>(() => {}));
    vi.stubGlobal('fetch', fetch);
    renderShow(true);

    fireEvent.focus(screen.getByRole('button', { name: '\u{1F44D} 2' }));
    expect(fetch).toHaveBeenCalledWith('/topics/11/reactions', expect.objectContaining({ credentials: 'same-origin' }));
    expect(fetch).not.toHaveBeenCalledWith(expect.stringContaining('/comments/'), expect.anything());
});

test('the hint stands only above comments, and goes once a reaction is made from a chip', () => {
    const fetch = vi.fn(() => new Promise<Response>(() => {}));
    vi.stubGlobal('fetch', fetch);
    renderShow(true, topic.reactions, [], 'shown');
    expect(screen.queryByText('Hover a row to react and more.')).toBeNull();

    cleanup();
    renderShow(true, topic.reactions, [comment], 'shown');
    expect(screen.getByText('Hover a row to react and more.')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: '\u{1F44D} 1' }));

    expect(screen.queryByText('Hover a row to react and more.')).toBeNull();
    expect(fetch).toHaveBeenCalledWith('/member/config/row-actions-hint', expect.objectContaining({ method: 'POST' }));
});
