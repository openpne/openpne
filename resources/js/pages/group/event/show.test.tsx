import { act, cleanup, fireEvent, screen } from '@testing-library/react';
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

afterEach(() => {
    cleanup();
    over = {};
});

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

let over: Partial<typeof event> = {};
const inertiaWith = (fields: Partial<typeof event>) => {
    over = fields;
};

const thread: EventThread = { comments: [], total: 0, page: 1, lastPage: 1, ascending: true, hasOlder: false, hasNewer: false, olderPage: null, newerPage: null };

function renderShow(canEdit: boolean, canComment = true) {
    inertia.page = {
        component: 'group/event/show',
        url: '/events/12',
        props: {
            group: { id: 2, name: 'A group', description: '', memberCount: 1, imageUrl: null, category: null },
            event: { ...event, ...over },
            thread,
            canComment,
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

/** The page offers a non-member the counts and nothing else: no add button, no menu, the chips as text. */
test('a reader who is not a member sees the event as counts alone', () => {
    inertiaWith({ reactions: [{ emoji: '\u{1F44D}', count: 2, mine: false }] });
    renderShow(false, false);

    expect(screen.queryByRole('button', { name: 'Add a reaction' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'More actions' })).toBeNull();
    expect(screen.getByText('2')).toBeTruthy();
    expect(screen.queryByRole('button', { pressed: false })).toBeNull();
});

/** The body and its comments are reacted to on different URLs, and nothing else ties the page's add button to the body's. */
test('reacting to the body posts to the body route, not a comment', async () => {
    const fetched = vi.fn((url: string) => Promise.resolve(new Response(JSON.stringify({ reactions: [], url }), { status: 200, headers: { 'Content-Type': 'application/json' } })));
    vi.stubGlobal('fetch', fetched);
    renderShow(false);

    fireEvent.click(screen.getByRole('button', { name: 'Add a reaction' }));
    fireEvent.click(screen.getByRole('button', { name: '\u{1F44D}' }));
    await act(() => new Promise((resolve) => setTimeout(resolve, 0)));

    expect(fetched.mock.calls[0]?.[0]).toBe('/events/12/reactions');
    vi.unstubAllGlobals();
});
