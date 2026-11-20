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
    openDate: null,
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

test('an event row leads with its open date; a topic row has none', () => {
    renderWithProviders(<ActivityRow entry={{ ...entry(0), kind: 'event', participantCount: 4, openDate: '2030-04-05' }} />);
    expect(screen.getByText(/Open date:/)).toBeTruthy();

    cleanup();
    renderWithProviders(<ActivityRow entry={entry(0)} />);
    expect(screen.queryByText(/Open date:/)).toBeNull();
});

test('in a list that mixes kinds, a row opens on its kind and group, a mark outside the link that stands in for the note and the byline name', () => {
    renderWithProviders(<ActivityRow entry={{ ...entry(0), kind: 'event', participantCount: 4, openDate: '2030-04-05' }} marked />);
    const word = screen.getByText('Event');
    const mark = word.parentElement;
    expect(mark?.closest('a')).toBeNull();
    expect(mark?.textContent).toBe('Event·Book club');
    expect(screen.getAllByText('Book club')).toHaveLength(1);
    expect(mark?.nextElementSibling?.textContent?.startsWith('Open date')).toBe(true);
    const glyph = mark?.querySelector('svg');
    expect(glyph?.classList.contains('lucide-calendar-days')).toBe(true);
    expect(glyph?.getAttribute('aria-hidden')).toBe('true');

    cleanup();
    renderWithProviders(<ActivityRow entry={entry(0)} marked />);
    expect(screen.getAllByText('%Topic%')).toHaveLength(1);
    expect(screen.getByText('%Topic%').parentElement?.querySelector('svg.lucide-message-square-text')).toBeTruthy();

    cleanup();
    renderWithProviders(<ActivityRow entry={entry(0)} />);
    expect(screen.getAllByText('%Topic%')).toHaveLength(1);
    expect(screen.getAllByText('Book club')).toHaveLength(1);
    expect(document.querySelector('svg.lucide-message-square-text')).toBeNull();
});
