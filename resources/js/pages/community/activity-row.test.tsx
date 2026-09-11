import { cleanup, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { ActivityRow, type CommunityActivityEntry } from './activity-row';
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

const entry = (commentCount: number): CommunityActivityEntry => ({
    kind: 'topic',
    id: 7,
    name: 'Reading list',
    commentCount,
    participantCount: null,
    group: { id: 5, name: 'Book club', imageUrl: null },
    bumpedAt: '2026-08-27T11:00:00+09:00',
});

test('the stamp says what it is: the last comment when there is one, the post otherwise', () => {
    renderWithProviders(<ActivityRow entry={entry(3)} />);
    expect(screen.getByText(/Last comment:/)).toBeTruthy();
    expect(screen.queryByText(/Posted:/)).toBeNull();

    cleanup();
    renderWithProviders(<ActivityRow entry={entry(0)} />);
    expect(screen.getByText(/Posted:/)).toBeTruthy();
    expect(screen.queryByText(/Last comment:/)).toBeNull();
});
