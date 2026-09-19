import { cleanup, fireEvent, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import GroupEventShow from './show';
import type { EventDetail, EventThread } from '@/pages/community/types';
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
    useForm: () => ({ data: { body: '', images: [] }, errors: {}, processing: false, setData: () => {}, transform: () => {}, post: () => {}, reset: () => {} }),
}));

afterEach(cleanup);

const event: EventDetail = {
    id: 12,
    name: 'An event',
    body: 'words',
    format: 'plain',
    bodyHtml: null,
    images: [],
    linkCard: null,
    author: { id: 3, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false },
    createdAt: '2026-09-19T10:00:00+09:00',
    editedAt: null,
    openDate: '2026-10-01',
    openDateComment: '',
    area: '',
    applicationDeadline: null,
    capacity: null,
    participantCount: 0,
    reactions: [],
};

const thread: EventThread = { comments: [], total: 0, page: 1, lastPage: 1, ascending: true, hasOlder: false, hasNewer: false, olderPage: null, newerPage: null };

function renderShow(canEdit: boolean) {
    inertia.page = {
        component: 'group/event/show',
        url: '/events/12',
        props: {
            group: { id: 2, name: 'A group', description: '', memberCount: 1, imageUrl: null, category: null },
            event,
            thread,
            canComment: true,
            canEdit,
            isParticipant: false,
            rosterOpen: true,
            isFull: false,
            reactionVocabulary: ['\u{1F44D}'],
            renderGeneration: 'g1',
            auth: { user: { id: 3 } },
            locale: 'en',
            timezone: 'Asia/Tokyo',
            imageUpload: { accept: 'image/png' },
        },
    };

    return renderWithProviders(<GroupEventShow />);
}

test('an editor edits and deletes the event from its menu; every member reacts to it and lists its reactors', () => {
    renderShow(true);
    expect(screen.getAllByRole('button', { name: 'Add a reaction' })).toHaveLength(1);
    fireEvent.keyDown(screen.getByRole('button', { name: 'More actions' }), { key: 'Enter' });
    expect(screen.getByRole('menuitem', { name: 'Edit' }).getAttribute('href')).toBe('/events/12/edit');
    expect(screen.getByRole('menuitem', { name: 'Delete' })).toBeTruthy();
    expect(screen.getByRole('menuitem', { name: 'See who reacted' })).toBeTruthy();

    cleanup();
    renderShow(false);
    fireEvent.keyDown(screen.getByRole('button', { name: 'More actions' }), { key: 'Enter' });
    expect(screen.queryByRole('menuitem', { name: 'Edit' })).toBeNull();
    expect(screen.getByRole('menuitem', { name: 'See who reacted' })).toBeTruthy();
});
