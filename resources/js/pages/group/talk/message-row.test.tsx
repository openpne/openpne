import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { TalkMessageRow } from './message-row';
import { fakeT } from '@/lib/test-i18n';
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

const message: TalkMessage = {
    id: 7,
    cursor: '7',
    body: 'Bring the good rope',
    author: { id: 3, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false },
    mentions: [],
    images: [],
    reactions: [],
    inReplyTo: null,
    createdAt: '2026-08-16T10:00:00+09:00',
    isOwn: true,
    canDelete: true,
};

function renderRow(over: Partial<TalkMessage> = {}, props: { canReply?: boolean; onReply?: () => void; onJumpToReply?: (parent: { id: number; cursor: string }) => void } = {}) {
    return render(
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
    render(
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
