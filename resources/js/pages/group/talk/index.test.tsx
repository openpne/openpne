import { act, cleanup, fireEvent, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import GroupTalkIndex from './index';
import type { TalkRowReactions } from './message-row';
import type { TalkMessage } from './types';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

const message = (id: number): TalkMessage => ({
    id,
    cursor: String(id),
    body: `message ${id}`,
    author: { id: 3, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false },
    mentions: [],
    images: [],
    files: [],
    reactions: [],
    inReplyTo: null,
    linkCard: null,
    createdAt: '2026-08-16T10:00:00+09:00',
    isOwn: false,
    canDelete: false,
});

const world = vi.hoisted(() => ({
    drawn: new Map<number, number>(),
    stream: null as unknown,
    props: null as unknown,
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: world.props }),
    Link: ({ href, children }: { href: string; children?: ReactNode }) => <a href={href}>{children}</a>,
    Head: () => null,
}));

vi.mock('@/lib/chat/use-chat-stream', () => ({ useChatStream: () => world.stream }));
vi.mock('@/lib/chat/use-mark-read', () => ({ useMarkRead: () => {} }));
vi.mock('./composer', () => ({ TalkComposer: () => null }));
vi.mock('./mute-toggle', () => ({ TalkMuteToggle: () => null }));

vi.mock('./message-row', () => ({
    TalkMessageRow: ({ message: row, reactions }: { message: TalkMessage; reactions: TalkRowReactions }) => {
        world.drawn.set(row.id, (world.drawn.get(row.id) ?? 0) + 1);

        return (
            <li data-talk-message-id={row.id}>
                <button type="button" onClick={() => reactions.onToggle('👍', false)}>
                    react to {row.id}
                </button>
            </li>
        );
    },
}));

const react = vi.fn((): Promise<void> => new Promise(() => {}));

beforeEach(() => {
    world.drawn.clear();
    react.mockClear();
    world.stream = {
        messages: [message(1), message(2), message(3)],
        window: { kind: 'latest' },
        generation: 0,
        hasOlder: false,
        loadingOlder: false,
        loadingNewer: false,
        react,
    };
    world.props = {
        group: { id: 9, name: 'Climbers' },
        page: {},
        anchor: null,
        canPost: true,
        isMember: true,
        isMuted: false,
        talkUnreadSnapshot: null,
        reactionsVersion: 0,
        reactionVocabulary: ['👍'],
        rowActionsHint: 'seen',
        locale: 'en',
        timezone: 'Asia/Tokyo',
    };
    vi.stubGlobal('scrollTo', vi.fn());
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

test('a page render that changes nothing about a row does not draw it again', () => {
    renderWithProviders(<GroupTalkIndex />);
    expect([...world.drawn.values()]).toEqual([1, 1, 1]);

    // Leaving the foot is page state: it raises the pill and is none of a row's business.
    vi.spyOn(document.documentElement, 'scrollHeight', 'get').mockReturnValue(5000);
    fireEvent.scroll(window);

    expect(screen.getByRole('button', { name: 'Jump to latest' })).toBeTruthy();
    expect([...world.drawn.values()]).toEqual([1, 1, 1]);
});

test('a tap on a row that was not drawn again still reaches the page as it now stands', () => {
    renderWithProviders(<GroupTalkIndex />);

    fireEvent.click(screen.getByRole('button', { name: 'react to 2' }));
    expect(react).toHaveBeenCalledTimes(1);
    expect(react).toHaveBeenCalledWith(2, '👍', 'add');
    // Only the tapped row carries the pending chip.
    expect([...world.drawn.values()]).toEqual([1, 2, 1]);

    // Row 1 still holds the handlers it was first drawn with; the page behind them now knows row
    // 2's tap is out, and a second one on it is refused rather than sent.
    act(() => fireEvent.click(screen.getByRole('button', { name: 'react to 1' })));
    fireEvent.click(screen.getByRole('button', { name: 'react to 2' }));
    expect(react.mock.calls).toEqual([
        [2, '👍', 'add'],
        [1, '👍', 'add'],
    ]);
});
