import { act, cleanup, fireEvent, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { TalkMessageRow } from './message-row';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
import type { TalkMessage } from './types';

// useT reads the Inertia page for its term map, which a component test has no page to give it.
vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

vi.mock('@inertiajs/react', () => ({
    // The row's timestamps are formatted in the site's clock and locale, which live in shared props.
    usePage: () => ({ props: { locale: 'en', timezone: 'Asia/Tokyo' } }),
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

afterEach(cleanup);
// Fake timers must not outlive a failing assertion in the test that arms them.
afterEach(() => vi.useRealTimers());
// Likewise the clipboard: vitest shares the environment across a file, so a shadow left in place
// would be what every later test in it sees.
afterEach(() => delete (navigator as { clipboard?: unknown }).clipboard);

const message: TalkMessage = {
    id: 7,
    cursor: '7',
    body: 'Bring the good rope',
    author: { id: 3, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false },
    mentions: [],
    images: [],
    reactions: [],
    inReplyTo: null,
    linkCard: null,
    createdAt: '2026-08-16T10:00:00+09:00',
    isOwn: true,
    canDelete: true,
};

function renderRow(
    over: Partial<TalkMessage> = {},
    props: { canReply?: boolean; canReact?: boolean; grouped?: boolean; onReply?: () => void; onDelete?: (id: number) => void; onJumpToReply?: (parent: { id: number; cursor: string }) => void } = {},
) {
    return renderWithProviders(
        <ul>
            <TalkMessageRow
                message={{ ...message, ...over }}
                onDelete={props.onDelete ?? vi.fn()}
                onReply={props.onReply ?? vi.fn()}
                onJumpToReply={props.onJumpToReply ?? vi.fn()}
                canReply={props.canReply ?? true}
                grouped={props.grouped ?? false}
                reactions={{ chips: [], vocabulary: ['👍'], canReact: props.canReact ?? true, onToggle: vi.fn(), onShowReactors: vi.fn() }}
            />
        </ul>,
    );
}

const tick = () => act(() => new Promise((resolve) => setTimeout(resolve, 0)));
const openMenu = () => fireEvent.keyDown(screen.getByRole('button', { name: 'More actions' }), { key: 'Enter' });

test('a reader who may not post gets no add button, and a menu that offers only the reactor list, disabled while there are no chips', () => {
    clipboard(null);
    renderRow({ canDelete: false }, { canReply: false, canReact: false });

    expect(screen.queryByRole('button', { name: 'Add a reaction' })).toBeNull();
    openMenu();
    expect(screen.getAllByRole('menuitem').map((item) => item.textContent)).toEqual(['See who reacted']);
    expect(screen.getByRole('menuitem', { name: 'See who reacted' }).getAttribute('aria-disabled')).toBe('true');
});

/** The live reference a reply draws above its header, distinct from the row's own author and body. */
const liveReply = {
    deleted: false as const,
    id: 3,
    cursor: 'c3',
    author: { id: 5, name: 'Mei', imageUrl: null, avatarColor: null, isAi: false },
    excerpt: 'the plan we discussed',
    thumbnailUrl: null as string | null,
};

test.each([false, true])('a row (grouped: %s) offers reacting on the row and replying, copying and deleting in its menu', async (grouped) => {
    clipboard(vi.fn(() => Promise.resolve()));
    const onReply = vi.fn();
    const onDelete = vi.fn();
    renderRow({}, { grouped, onReply, onDelete });

    expect(screen.getByRole('button', { name: 'Add a reaction' })).toBeTruthy();
    openMenu();
    expect(screen.getAllByRole('menuitem').map((item) => item.textContent)).toEqual(['See who reacted', 'Reply', 'Copy text', 'Copy link', 'Delete message']);

    fireEvent.click(screen.getByRole('menuitem', { name: 'Delete message' }));
    await tick();
    expect(onDelete).toHaveBeenCalledWith(7);
});

test('a folded row keeps its menu beside the add button, since it has no header line to hold it', () => {
    const { container } = renderRow({}, { grouped: true });

    const menu = screen.getByRole('button', { name: 'More actions' });
    expect(menu.parentElement?.contains(screen.getByRole('button', { name: 'Add a reaction' }))).toBe(true);
    expect(container.querySelector('a[href="/member/3"]')).toBeNull();
});

test('a reply draws its reference above the row: the parent author, the excerpt, and a live jump', () => {
    const onJumpToReply = vi.fn();
    renderRow({ inReplyTo: liveReply }, { onJumpToReply });

    // The parent author and its excerpt read as context — distinct from the row's own author (Rin) and body.
    expect(screen.getByText('Mei')).toBeTruthy();
    expect(screen.getByText('the plan we discussed')).toBeTruthy();

    // The matcher is a predicate because the who-and-what has to be in the accessible name, not a
    // bare "go to".
    const jump = screen.getByRole('button', {
        name: (name: string) => name.includes('Go to the replied message') && name.includes('Mei') && name.includes('the plan we discussed'),
    });
    fireEvent.click(jump);
    expect(onJumpToReply.mock.calls).toEqual([[{ id: 3, cursor: 'c3' }]]);
});

test('a reply to a withdrawn author names them with the established label', () => {
    renderRow({ inReplyTo: { ...liveReply, author: null } });

    // The label is in the button's accessible name, and the row's own author (Rin) is present, so it
    // can only come from the reference.
    expect(screen.getByRole('button', { name: (name: string) => name.includes('Withdrawn member') })).toBeTruthy();
    expect(screen.getByText('Withdrawn member')).toBeTruthy();
});

test('the reference shows the parent thumbnail when it has one, and none when it does not', () => {
    const withThumb = renderRow({ inReplyTo: { ...liveReply, thumbnailUrl: 'https://sns.test/thumb.jpg' } });
    expect(withThumb.container.querySelector('img[src="https://sns.test/thumb.jpg"]')).not.toBeNull();

    cleanup();
    const withoutThumb = renderRow({ inReplyTo: { ...liveReply, thumbnailUrl: null } });
    expect(withoutThumb.container.querySelector('img[src="https://sns.test/thumb.jpg"]')).toBeNull();
});

test('a reply to a deleted parent reads as deleted and is not a jump', () => {
    renderRow({ inReplyTo: { deleted: true } });

    expect(screen.getByText('Deleted message')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Go to the replied message' })).toBeNull();
});

test('a reader who may not post is offered no reply', () => {
    renderRow({}, { canReply: false });
    openMenu();

    expect(screen.queryByRole('menuitem', { name: 'Reply' })).toBeNull();
});

test('the row carries the time alone, beside the author rather than at the far edge', () => {
    const { container } = renderRow();

    const stamp = container.querySelector('time');
    expect(stamp?.textContent).toBe('10:00');
    expect(stamp?.getAttribute('datetime')).toBe('2026-08-16T10:00:00+09:00');
    expect(stamp?.className).not.toContain('ml-auto');
});

test('a folded row keeps its time in the gutter, spoken as well as drawn', () => {
    const { container } = renderRow({}, { grouped: true });

    // Counted rather than located: a selector down the row's boxes resolves to the gutter only by
    // today's order of children, and the companion test below asserts an absence.
    const stamps = [...container.querySelectorAll('time')];
    // Two lanes, one minute: what a cursor reveals, and what a screen reader is told instead.
    expect(stamps.map((stamp) => stamp.textContent)).toEqual(['10:00', '10:00']);
    // Whether the drawn one is visible is the hover rule, which a component test never loads; that it
    // is hidden from the spoken lane is here.
    expect(stamps.filter((stamp) => stamp.closest('[aria-hidden]') !== null)).toHaveLength(1);
    expect(stamps.filter((stamp) => stamp.closest('.sr-only') !== null)).toHaveLength(1);
});

test('a row that draws its author draws no gutter time: it already shows one beside the name', () => {
    const { container } = renderRow();

    // Counting is what makes this fail if a stamp appears rather than if a selector stops finding
    // one.
    const stamps = [...container.querySelectorAll('time')];
    expect(stamps).toHaveLength(1);
    expect(stamps[0]?.closest('[aria-hidden]')).toBeNull();
});

test('a message whose body holds a link draws the card under the words', () => {
    const { container } = renderRow({
        body: 'Look at https://example.com/a',
        linkCard: {
            url: 'https://example.com/a',
            title: 'A title from the page',
            description: 'What the page says it is about.',
            siteName: 'Example',
            domain: 'example.com',
            layout: 'compact',
            imageUrl: null,
            imageWidth: null,
            imageHeight: null,
            fitSources: [],
        },
    });

    // The body keeps its own link, so the card is the second way to the same page.
    expect(container.querySelectorAll('a[href="https://example.com/a"]').length).toBe(2);

    const card = screen.getByText('A title from the page').closest('a');
    expect(card).not.toBeNull();
    expect(card?.textContent).toContain('example.com');
    expect(card?.className).not.toMatch(/\bmax-w-/);
});

test('a message with no card leaves the body link standing alone', () => {
    const { container } = renderRow({ body: 'Look at https://example.com/a', linkCard: null });

    expect(container.querySelectorAll('a[href="https://example.com/a"]').length).toBe(1);
});

/**
 * What the row asks the platform for — absent stands for a site served over plain http, which has no
 * Clipboard API at all. happy-dom answers from a prototype getter, so this shadows it with an own
 * property and the afterEach above deletes that back off.
 */
function clipboard(writeText: ((text: string) => Promise<void>) | null) {
    Object.defineProperty(navigator, 'clipboard', { value: writeText === null ? undefined : { writeText }, configurable: true });
}

async function chooseCopy(name: 'Copy link' | 'Copy text') {
    openMenu();
    fireEvent.click(screen.getByRole('menuitem', { name }));
    await tick();
    await act(async () => {
        await Promise.resolve();
    });
}

test('the menu copies the message link and the body', async () => {
    const writeText = vi.fn(() => Promise.resolve());
    clipboard(writeText);
    window.history.replaceState(null, '', '/groups/3/talk');
    renderRow();

    await chooseCopy('Copy link');
    expect(writeText).toHaveBeenCalledWith(`${window.location.origin}/groups/3/talk?m=7`);
    await chooseCopy('Copy text');
    expect(writeText).toHaveBeenLastCalledWith('Bring the good rope');
    window.history.replaceState(null, '', '/');
});

test('no clipboard leaves the menu without either copy', () => {
    clipboard(null);
    renderRow();
    openMenu();

    expect(screen.queryByRole('menuitem', { name: 'Copy link' })).toBeNull();
    expect(screen.queryByRole('menuitem', { name: 'Copy text' })).toBeNull();
});

test('a completed copy is answered on the row, spoken and shown, then the answer clears', async () => {
    const writeText = vi.fn(() => Promise.resolve());
    clipboard(writeText);
    window.history.replaceState(null, '', '/groups/3/talk');
    renderRow();

    await chooseCopy('Copy link');

    // Spoken on completion, not on the choice: the acknowledgement claims the write happened, and it
    // lives on the row because the menu it was chosen from is gone by then.
    expect(screen.getAllByText('Link copied.')).toHaveLength(2);
    expect(screen.getByText('Link copied.', { selector: '[aria-live] *, [aria-live]' })).toBeTruthy();

    await act(() => new Promise((resolve) => setTimeout(resolve, 1600)));
    expect(screen.queryByText('Link copied.')).toBeNull();
    window.history.replaceState(null, '', '/');
});

test('a refused copy says so rather than letting the old clipboard read as success', async () => {
    const writeText = vi.fn(() => Promise.reject(new Error('denied')));
    clipboard(writeText);
    renderRow();

    await chooseCopy('Copy text');

    expect(screen.queryByText('Text copied.')).toBeNull();
    expect(screen.getAllByText('The text could not be copied.').length).toBeGreaterThan(0);
});

test('a write that completes after the row left schedules nothing', async () => {
    let settle = () => {};
    const writeText = vi.fn(() => new Promise<void>((resolve) => (settle = resolve)));
    clipboard(writeText);
    window.history.replaceState(null, '', '/groups/3/talk');
    const view = renderRow();

    openMenu();
    fireEvent.click(screen.getByRole('menuitem', { name: 'Copy link' }));
    await tick();
    vi.useFakeTimers();
    view.unmount();
    settle();
    await act(async () => {
        await Promise.resolve();
    });

    // The guard's observable half: without it the late settle schedules the clear-timer anyway.
    expect(vi.getTimerCount()).toBe(0);
    window.history.replaceState(null, '', '/');
});
