import { cleanup, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { type FeedItem, NotificationRow } from './notification-row';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'en', timezone: 'Asia/Tokyo' } }),
    Link: ({ href, children }: { href: string; children?: ReactNode }) => (
        <button type="button" data-href={href}>
            {children}
        </button>
    ),
}));

afterEach(cleanup);

const item = (contentKind: FeedItem['contentKind']): FeedItem => ({
    id: 'a1',
    kind: 'diary_commented',
    contentKind,
    reason: null,
    label: 'Aoi commented on your diary.',
    createdAt: '2026-11-20T10:00:00Z',
    read: true,
    actor: null,
});

test('a row opens on the kind of what it points at, a word inside the one button and a glyph that is not read', () => {
    renderWithProviders(<NotificationRow item={item('diary')} />);

    const row = screen.getByRole('button');
    const word = screen.getByText('%Diary%');
    expect(row.contains(word)).toBe(true);
    expect(row.textContent?.startsWith('%Diary%Aoi commented on your diary.')).toBe(true);
    const glyph = word.parentElement?.querySelector('svg');
    expect(glyph?.classList.contains('lucide-book-open')).toBe(true);
    expect(glyph?.getAttribute('aria-hidden')).toBe('true');
    expect(row.querySelector('p, div')).toBeNull();
});

test('a row that points at nothing known has no mark', () => {
    renderWithProviders(<NotificationRow item={item(null)} />);

    expect(screen.getByRole('button').querySelector('svg.lucide')).toBeNull();
});
