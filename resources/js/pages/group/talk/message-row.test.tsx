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
// Fake timers must not outlive a failing assertion in the tests that arm them.
afterEach(() => vi.useRealTimers());

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

function renderRow(over: Partial<TalkMessage> = {}, props: { canReply?: boolean; onReply?: () => void; onJumpToReply?: (parent: { id: number; cursor: string }) => void } = {}) {
    return renderWithProviders(
        <ul>
            <TalkMessageRow
                message={{ ...message, ...over }}
                onDelete={vi.fn()}
                onOpenActions={vi.fn()}
                onReply={props.onReply ?? vi.fn()}
                onJumpToReply={props.onJumpToReply ?? vi.fn()}
                canReply={props.canReply ?? true}
                reactions={{ chips: [], vocabulary: ['👍'], canReact: true, onToggle: vi.fn(), onShowReactors: vi.fn() }}
            />
        </ul>,
    );
}

/** A row with nothing a press could open — no react, reply, delete, chip, or clipboard. */
function renderInertRow() {
    return renderWithProviders(
        <ul>
            <TalkMessageRow
                message={{ ...message, canDelete: false }}
                onDelete={vi.fn()}
                onOpenActions={vi.fn()}
                onReply={vi.fn()}
                onJumpToReply={vi.fn()}
                canReply={false}
                reactions={{ chips: [], vocabulary: ['\u{1F44D}'], canReact: false, onToggle: vi.fn(), onShowReactors: vi.fn() }}
            />
        </ul>,
    );
}

const SUPPRESSION = ['pointer-coarse:select-none', 'pointer-coarse:[-webkit-touch-callout:none]'];

/** Which of the two the row carries — not whether it carries them all, so one slipping back to
 *  unconditional on its own still shows up on the inert row. */
function suppressionOn(): string[] {
    const row = document.querySelector('[data-talk-message-id]')!;

    return SUPPRESSION.filter((c) => row.classList.contains(c));
}

/**
 * happy-dom answers `navigator.clipboard` from a prototype getter, so the secure-context case has to
 * be built by shadowing it. What is left is the shape of a site served over plain http, where the
 * Clipboard API is not exposed at all.
 */
function withoutClipboard(body: () => void) {
    const own = Object.getOwnPropertyDescriptor(navigator, 'clipboard');
    Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
    try {
        body();
    } finally {
        if (own) Object.defineProperty(navigator, 'clipboard', own);
        else delete (navigator as { clipboard?: unknown }).clipboard;
    }
}

test("takes the finger's own gestures only where a press opens something", () => {
    renderRow();
    expect(suppressionOn()).toEqual(SUPPRESSION);

    cleanup();

    withoutClipboard(() => {
        renderInertRow();
        expect(suppressionOn()).toEqual([]);
    });
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

/**
 * Both branches carry the same controls under the same names. What a screen shows of them is CSS the
 * component test never loads — the reveal and the touch lane are checked in the browser (tools/ux-review),
 * not here — so this asserts only that the names exist to be reached at all, which is what the
 * `sr-only` lane rests on.
 */
test.each([false, true])('a row (grouped: %s) offers reacting, replying and deleting by name', (grouped) => {
    renderWithProviders(
        <ul>
            <TalkMessageRow
                message={message}
                onDelete={vi.fn()}
                onOpenActions={vi.fn()}
                onReply={vi.fn()}
                onJumpToReply={vi.fn()}
                canReply={true}
                grouped={grouped}
                reactions={{ chips: [], vocabulary: ['👍'], canReact: true, onToggle: vi.fn(), onShowReactors: vi.fn() }}
            />
        </ul>,
    );

    // The head of the vocabulary is in the row itself, not only behind the picker the button opens.
    expect(screen.getByRole('button', { name: '👍' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Add a reaction' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Reply' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Delete message' })).toBeTruthy();
});

test('a reply draws its reference above the row: the parent author, the excerpt, and a live jump', () => {
    const onJumpToReply = vi.fn();
    renderRow({ inReplyTo: liveReply }, { onJumpToReply });

    // The parent author and its excerpt read as context — distinct from the row's own author (Rin) and body.
    expect(screen.getByText('Mei')).toBeTruthy();
    expect(screen.getByText('the plan we discussed')).toBeTruthy();

    // The whole line is one button, and its accessible name carries the who-and-what — not a bare
    // "go to" label an aria-label would flatten every reference to. Activating it goes to the
    // referenced message by id and cursor.
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
    // Plain text, no button semantics: there is nowhere to jump to.
    expect(screen.queryByRole('button', { name: 'Go to the replied message' })).toBeNull();
});

test('a reader who may not post is offered no reply control', () => {
    renderRow({}, { canReply: false });

    expect(screen.queryByRole('button', { name: 'Reply' })).toBeNull();
});

test('the row carries the time alone, beside the author rather than at the far edge', () => {
    const { container } = renderRow();

    const stamp = container.querySelector('time');
    // The day is said once by the heading above the run, so the row says only the hour and minute.
    expect(stamp?.textContent).toBe('10:00');
    // Still the whole instant for machines, and the whole value on hover.
    expect(stamp?.getAttribute('datetime')).toBe('2026-08-16T10:00:00+09:00');
    // Nothing pushes it away from the name it belongs to.
    expect(stamp?.className).not.toContain('ml-auto');
});

test('a folded row keeps its time in the gutter, spoken as well as drawn', () => {
    const { container } = renderWithProviders(
        <ul>
            <TalkMessageRow
                message={message}
                onDelete={vi.fn()}
                onOpenActions={vi.fn()}
                onReply={vi.fn()}
                onJumpToReply={vi.fn()}
                canReply={true}
                grouped
                reactions={{ chips: [], vocabulary: ['👍'], canReact: true, onToggle: vi.fn(), onShowReactors: vi.fn() }}
            />
        </ul>,
    );

    // Counted rather than located. A selector down the row's boxes resolves to the gutter only by
    // today's order of children, and the companion test below asserts an *absence* — which such a
    // selector would go on passing the moment it stopped pointing at the gutter at all.
    const stamps = [...container.querySelectorAll('time')];
    // Two lanes, one minute: what a cursor reveals, and what a screen reader is told instead.
    expect(stamps.map((stamp) => stamp.textContent)).toEqual(['10:00', '10:00']);
    // Whether the drawn one is *visible* is the hover rule, which this test never loads
    // (tools/ux-review drives that in a browser); that it is hidden from the spoken lane is here.
    expect(stamps.filter((stamp) => stamp.closest('[aria-hidden]') !== null)).toHaveLength(1);
    expect(stamps.filter((stamp) => stamp.closest('.sr-only') !== null)).toHaveLength(1);
});

test('a row that draws its author draws no gutter time: it already shows one beside the name', () => {
    const { container } = renderRow();

    // One stamp, and it is the one beside the name: a second would be the gutter's, drawn on a row
    // that never folded. Counting is what makes this fail if a stamp appears rather than if a
    // selector stops finding one.
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
    // The domain is what a reader acts on, and it is drawn whatever the title claims.
    expect(card?.textContent).toContain('example.com');
    // No width of its own: a card restates something in the message, so it runs to the words' edge
    // rather than stopping short of them (link-card.tsx).
    expect(card?.className).not.toMatch(/\bmax-w-/);
});

test('a message with no card leaves the body link standing alone', () => {
    const { container } = renderRow({ body: 'Look at https://example.com/a', linkCard: null });

    expect(container.querySelectorAll('a[href="https://example.com/a"]').length).toBe(1);
});

/** What the row asks the platform for — absent stands for a site served without a secure context. */
function clipboard(writeText: ((text: string) => Promise<void>) | null) {
    Object.defineProperty(navigator, 'clipboard', { value: writeText === null ? undefined : { writeText }, configurable: true });
}

test('the actions bar copies the message link in one click', () => {
    const writeText = vi.fn(() => Promise.resolve());
    clipboard(writeText);
    window.history.replaceState(null, '', '/groups/3/talk');
    renderRow();

    fireEvent.click(screen.getByRole('button', { name: 'Copy link' }));

    expect(writeText).toHaveBeenCalledWith(`${window.location.origin}/groups/3/talk?m=7`);
    window.history.replaceState(null, '', '/');
});

test('no clipboard leaves the bar without a link button', () => {
    clipboard(null);
    renderRow();

    expect(screen.queryByRole('button', { name: 'Copy link' })).toBeNull();
});

test('a completed copy answers with a check and a spoken line, then offers again', async () => {
    vi.useFakeTimers();
    const writeText = vi.fn(() => Promise.resolve());
    clipboard(writeText);
    window.history.replaceState(null, '', '/groups/3/talk');
    renderRow();

    fireEvent.click(screen.getByRole('button', { name: 'Copy link' }));
    await act(async () => {
        await Promise.resolve();
    });

    // Spoken on completion, not on the click: the acknowledgement claims the write happened.
    expect(screen.getByText('Link copied.')).toBeTruthy();
    // data-ack is what holds the bar out while the answer is showing.
    expect(screen.getByRole('button', { name: 'Copy link' }).getAttribute('data-ack')).toBe('copied');

    act(() => {
        vi.advanceTimersByTime(1600);
    });
    expect(screen.queryByText('Link copied.')).toBeNull();
    expect(screen.getByRole('button', { name: 'Copy link' }).getAttribute('data-ack')).toBeNull();
    window.history.replaceState(null, '', '/');
});

test('a refused copy says so rather than letting the old clipboard read as success', async () => {
    const writeText = vi.fn(() => Promise.reject(new Error('denied')));
    clipboard(writeText);
    renderRow();

    fireEvent.click(screen.getByRole('button', { name: 'Copy link' }));
    await act(async () => {
        await Promise.resolve();
    });

    expect(screen.queryByText('Link copied.')).toBeNull();
    expect(screen.getByText('The link could not be copied.')).toBeTruthy();
});

test('a write that completes after the row left schedules nothing', async () => {
    vi.useFakeTimers();
    let settle = () => {};
    const writeText = vi.fn(() => new Promise<void>((resolve) => (settle = resolve)));
    clipboard(writeText);
    window.history.replaceState(null, '', '/groups/3/talk');
    const view = renderRow();

    fireEvent.click(screen.getByRole('button', { name: 'Copy link' }));
    view.unmount();
    settle();
    await act(async () => {
        await Promise.resolve();
    });

    // The guard's observable half: without it the late settle schedules the clear-timer anyway.
    expect(vi.getTimerCount()).toBe(0);
    window.history.replaceState(null, '', '/');
});
