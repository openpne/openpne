import { cleanup, render, screen } from '@testing-library/react';
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
    createdAt: '2026-08-16T10:00:00+09:00',
    isOwn: true,
    canDelete: true,
};

/**
 * Both branches carry the same controls under the same names. What a screen shows of them is CSS the
 * component test never loads — the reveal and the touch lane are checked in the browser (tools/ux-review),
 * not here — so this asserts only that the names exist to be reached at all, which is what the
 * `sr-only` lane rests on.
 */
test.each([false, true])('a row (grouped: %s) offers reacting and deleting by name', (grouped) => {
    render(
        <ul>
            <TalkMessageRow
                message={message}
                onDelete={vi.fn()}
                onOpenActions={vi.fn()}
                grouped={grouped}
                reactions={{ chips: [], vocabulary: ['👍'], canReact: true, onToggle: vi.fn(), onShowReactors: vi.fn() }}
            />
        </ul>,
    );

    expect(screen.getByRole('button', { name: 'Add a reaction' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Delete' })).toBeTruthy();
});
