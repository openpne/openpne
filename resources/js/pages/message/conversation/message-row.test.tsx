import { cleanup } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { ConversationMessageRow } from './message-row';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
import type { ConversationMessage } from './types';

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

const message: ConversationMessage = {
    id: 7,
    cursor: '7',
    body: 'Fifteen hundred works',
    subject: null,
    author: { id: 3, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false },
    createdAt: '2026-08-16T10:00:00+09:00',
    isOwn: true,
    images: [],
    files: [],
};

function renderRow(over: Partial<ConversationMessage> = {}, grouped = false) {
    return renderWithProviders(
        <ul>
            <ConversationMessageRow message={{ ...message, ...over }} grouped={grouped} />
        </ul>,
    );
}

test('a row that opens a turn names its author and time', () => {
    const { container, getByRole } = renderRow();

    expect(getByRole('link', { name: 'Rin' })).toBeTruthy();
    const stamps = [...container.querySelectorAll('time')];
    expect(stamps).toHaveLength(1);
    expect(stamps[0]?.closest('[aria-hidden]')).toBeNull();
});

test('a folded row drops the header but still speaks its author and its time', () => {
    const { container, queryByRole } = renderRow({}, true);

    expect(queryByRole('link', { name: 'Rin' })).toBeNull();
    // Two lanes, one minute: what a cursor reveals in the gutter, and what a screen reader is told.
    const stamps = [...container.querySelectorAll('time')];
    expect(stamps.map((stamp) => stamp.textContent)).toEqual(['10:00', '10:00']);
    expect(stamps.filter((stamp) => stamp.closest('[aria-hidden]') !== null)).toHaveLength(1);
    expect(container.querySelector('.sr-only')?.textContent).toBe('Rin, 10:00');
});
