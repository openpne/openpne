import { cleanup, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import DiaryShow from './show';
import type { DiaryComment, DiaryDetail, DiaryThread } from './types';
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
    useForm: () => ({ data: { body: '', images: [] }, errors: {}, processing: false, setData: () => {}, post: () => {}, reset: () => {} }),
}));

afterEach(cleanup);

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
        props: { diary, thread, older: null, newer: null, auth: { user }, reactionVocabulary: ['\u{1F44D}'], renderGeneration: 'g1', locale: 'en', timezone: 'Asia/Tokyo', imageUpload: { accept: 'image/png' } },
    };

    return renderWithProviders(<DiaryShow />);
}

test('a signed-in reader may react to the entry and to each comment', () => {
    renderShow({ id: 9 });

    expect(screen.getAllByRole('button', { name: 'Add a reaction' })).toHaveLength(2);
    expect(screen.getAllByRole('button', { name: 'See who reacted' })).toHaveLength(2);
});

test('a guest on a web-public entry reads the counts and has nothing to press', () => {
    const { container } = renderShow(null);

    expect(container.querySelectorAll('[data-reactions]')).toHaveLength(2);
    expect(screen.queryByRole('button', { name: 'Add a reaction' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'See who reacted' })).toBeNull();
    expect(screen.getByText('2')).toBeTruthy();
});
