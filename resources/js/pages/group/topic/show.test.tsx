import { cleanup, fireEvent, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import GroupTopicShow from './show';
import type { TopicDetail, TopicThread } from '@/pages/community/types';
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
};

const thread: TopicThread = { comments: [], total: 0, page: 1, lastPage: 1, ascending: true, hasOlder: false, hasNewer: false, olderPage: null, newerPage: null };

function renderShow(canEdit: boolean) {
    inertia.page = {
        component: 'group/topic/show',
        url: '/topics/11',
        props: {
            group: { id: 2, name: 'A group', description: '', memberCount: 1, imageUrl: null, category: null },
            topic,
            thread,
            canComment: true,
            canEdit,
            reactionVocabulary: ['\u{1F44D}'],
            renderGeneration: 'g1',
            auth: { user: { id: 3 } },
            locale: 'en',
            timezone: 'Asia/Tokyo',
            imageUpload: { accept: 'image/png' },
        },
    };

    return renderWithProviders(<GroupTopicShow />);
}

test('an editor edits and deletes the topic from its menu; a reader who may not has no menu on it', () => {
    renderShow(true);
    fireEvent.keyDown(screen.getByRole('button', { name: 'More actions' }), { key: 'Enter' });
    expect(screen.getByRole('menuitem', { name: 'Edit' }).getAttribute('href')).toBe('/topics/11/edit');
    expect(screen.getByRole('menuitem', { name: 'Delete' })).toBeTruthy();

    cleanup();
    renderShow(false);
    expect(screen.queryByRole('button', { name: 'More actions' })).toBeNull();
});
