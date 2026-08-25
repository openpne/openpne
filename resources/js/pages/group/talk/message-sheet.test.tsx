import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { TalkMessageSheet } from './message-sheet';
import { fakeT } from '@/lib/test-i18n';
import type { ChatReactionChip } from '@/lib/chat/types';
import type { TalkMessage } from './types';

// useT reads the Inertia page for its term map, which a component test has no page to give it.
vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

afterEach(cleanup);

const VOCABULARY = ['👍', '🎉', '❤️', '😂', '😮', '🙏', '👀', '🔥'];

const message = (over: Partial<TalkMessage> = {}): TalkMessage => ({
    id: 7,
    cursor: '7',
    body: 'Bring the good rope',
    author: { id: 3, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false },
    mentions: [],
    images: [],
    linkCard: null,
    reactions: [],
    inReplyTo: null,
    createdAt: '2026-08-16T10:00:00+09:00',
    isOwn: false,
    canDelete: false,
    ...over,
});

/** What the sheet asks the platform for. Absent stands for a site served without a secure context. */
function clipboard(writeText: ((text: string) => Promise<void>) | null) {
    Object.defineProperty(navigator, 'clipboard', { value: writeText === null ? undefined : { writeText }, configurable: true });
}

function open({ chips = [], canReact = true, canReply = true, ...over }: { chips?: ChatReactionChip[]; canReact?: boolean; canReply?: boolean } & Partial<TalkMessage> = {}) {
    const spies = {
        onToggle: vi.fn(),
        onShowReactors: vi.fn(),
        onReply: vi.fn(),
        onDelete: vi.fn(),
        onClose: vi.fn(),
    };

    render(
        <TalkMessageSheet
            message={message(over)}
            chips={chips}
            vocabulary={VOCABULARY}
            canReact={canReact}
            canReply={canReply}
            onToggle={spies.onToggle}
            onShowReactors={spies.onShowReactors}
            onReply={spies.onReply}
            onDelete={spies.onDelete}
            onClose={spies.onClose}
        />,
    );

    return spies;
}

test('the link is offered whenever the clipboard is, and copies this conversation\'s own address', () => {
    const writeText = vi.fn(() => Promise.resolve());
    clipboard(writeText);
    window.history.replaceState(null, '', '/groups/3/talk?context=abc#foo');
    const spies = open({ body: '' });

    fireEvent.click(screen.getByRole('button', { name: 'Copy link' }));

    // The page's URL carrying only `m`: `context` names this visit's position, not the message's.
    expect(writeText).toHaveBeenCalledWith(`${window.location.origin}/groups/3/talk?m=7`);
    expect(spies.onClose).toHaveBeenCalled();
});

test('no clipboard, no link item', () => {
    clipboard(null);
    open();

    expect(screen.queryByRole('button', { name: 'Copy link' })).toBeNull();
});

test('the sheet is named for a reader who cannot see what it is', () => {
    clipboard(null);
    open();

    expect(screen.getByRole('dialog', { name: 'Message actions' })).toBeTruthy();
});

test('the sheet offers a reply, and taking it closes the sheet then stages it', () => {
    clipboard(null);
    const spies = open();

    fireEvent.click(screen.getByRole('button', { name: 'Reply' }));

    // Closed before staged: the composer the reply focuses sits where the sheet stood.
    expect(spies.onClose).toHaveBeenCalled();
    expect(spies.onReply).toHaveBeenCalled();
});

test('a reader who may not post is offered no reply', () => {
    clipboard(null);
    open({ canReply: false });

    expect(screen.queryByRole('button', { name: 'Reply' })).toBeNull();
});

test('the whole vocabulary is offered, and the ones already held say so', () => {
    clipboard(null);
    open({ chips: [{ emoji: '🎉', count: 2, mine: true }] });

    expect(VOCABULARY.map((emoji) => screen.getByRole('button', { name: emoji }).getAttribute('aria-pressed'))).toEqual([
        'false',
        'true',
        'false',
        'false',
        'false',
        'false',
        'false',
        'false',
    ]);
});

test('picking an emoji makes the tap and takes the sheet away with it', () => {
    clipboard(null);
    const spies = open({ chips: [{ emoji: '🎉', count: 2, mine: true }] });

    fireEvent.click(screen.getByRole('button', { name: '🎉' }));

    // What the chip already shows is what the tap means: holding it takes it back.
    expect(spies.onToggle.mock.calls).toEqual([['🎉', true]]);
    expect(spies.onClose).toHaveBeenCalled();
});

test('a reader who may not post gets no picker', () => {
    clipboard(null);
    open({ canReact: false });

    expect(screen.queryByRole('button', { name: '👍' })).toBeNull();
});

test('the reactor list is offered only where there are chips to look behind', () => {
    clipboard(null);
    open();

    expect(screen.queryByRole('button', { name: 'See who reacted' })).toBeNull();

    cleanup();
    const spies = open({ chips: [{ emoji: '👍', count: 1, mine: false }] });
    fireEvent.click(screen.getByRole('button', { name: 'See who reacted' }));

    expect(spies.onClose).toHaveBeenCalled();
    expect(spies.onShowReactors).toHaveBeenCalled();
});

test('deleting is offered only to someone who may', () => {
    clipboard(null);
    open();

    expect(screen.queryByRole('button', { name: 'Delete message' })).toBeNull();

    cleanup();
    const spies = open({ canDelete: true });
    fireEvent.click(screen.getByRole('button', { name: 'Delete message' }));

    // The sheet leaves first: the question the page asks next is a modal of its own.
    expect(spies.onClose).toHaveBeenCalled();
    expect(spies.onDelete).toHaveBeenCalled();
});

test('copying is offered for a message that has words, and copies them', () => {
    const writeText = vi.fn(() => Promise.resolve());
    clipboard(writeText);
    const spies = open();

    fireEvent.click(screen.getByRole('button', { name: 'Copy text' }));

    expect(writeText.mock.calls).toEqual([['Bring the good rope']]);
    expect(spies.onClose).toHaveBeenCalled();
});

test('a message that is nothing but pictures has no text to copy', () => {
    clipboard(vi.fn(() => Promise.resolve()));
    open({ body: '   ' });

    expect(screen.queryByRole('button', { name: 'Copy text' })).toBeNull();
});

/** No clipboard at all is what a site served over plain http gives: an offer that could not be kept. */
test('copying is not offered where the platform has no clipboard', () => {
    clipboard(null);
    open();

    expect(screen.queryByRole('button', { name: 'Copy text' })).toBeNull();
});
