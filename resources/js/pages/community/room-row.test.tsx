import { cleanup, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { RoomRow } from './room-row';
import type { TalkRoomRow } from './types';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'en', timezone: 'Asia/Tokyo' } }),
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

afterEach(cleanup);

const room = (latest: TalkRoomRow['latest']): TalkRoomRow => ({ id: 3, name: 'Book club', imageUrl: null, unread: 0, muted: false, latest });

const spoken = { body: 'see you there', authorName: 'Sato', authorIsAi: false, createdAt: '2026-08-20T10:00:00+09:00' };

const EMPTY = 'Nothing has been said in this talk yet.';

test('a row that opens the talk links there and says so when nothing was said', () => {
    renderWithProviders(<RoomRow room={room(null)} opens="talk" />);

    expect(screen.getByRole('link', { name: 'Book club' }).getAttribute('href')).toBe('/groups/3/talk');
    expect(screen.getByText(EMPTY)).toBeTruthy();
});

test('a row that opens the group links to its top and keeps quiet about an empty talk', () => {
    renderWithProviders(<RoomRow room={room(null)} opens="group" />);

    expect(screen.getByRole('link', { name: 'Book club' }).getAttribute('href')).toBe('/groups/3');
    expect(screen.queryByText(EMPTY)).toBeNull();
});

test('a row that opens the group still previews what was last said', () => {
    renderWithProviders(<RoomRow room={room(spoken)} opens="group" />);

    expect(screen.getByRole('link', { name: 'Book club' }).getAttribute('href')).toBe('/groups/3');
    expect(screen.getByText(/Sato: see you there/)).toBeTruthy();
});
