import { act, cleanup, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { UpcomingEventRow } from './event-row';
import { IssueNav } from './issue-nav';
import en from '../../../../lang/en.json';
import { renderWithProviders } from '@/lib/test-render';

const dictionary: Record<string, string> = en;
const translate = (key: string, replacements: Record<string, string | number> = {}): string =>
    Object.entries(replacements).reduce((line, [name, value]) => line.replaceAll(`:${name}`, String(value)), dictionary[key] ?? key);

vi.mock('@/lib/i18n', () => ({ useT: () => translate }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'en', timezone: 'Asia/Tokyo' } }),
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

beforeEach(() => {
    vi.useFakeTimers();
    // A minute before New Year in Tokyo.
    vi.setSystemTime(new Date('2026-12-31T14:59:00Z'));
});

afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

const midnight = () =>
    act(() => {
        vi.advanceTimersByTime(61_000);
    });

test('a day step left open into the new year takes the old year on', () => {
    renderWithProviders(
        <IssueNav prev={{ date: '2026-12-30', number: 9, href: '/home/2026/12/30' }} next={null} monthHref="/home/2026/12" />,
    );
    const step = () => screen.getByRole('link', { name: /Earlier day/ }).querySelector('time')?.textContent;
    expect(step()).toBe('Wed, Dec 30');

    midnight();

    expect(step()).toBe('Wed, Dec 30, 2026');
});

test('an event row left open into the new year takes the old year on', () => {
    renderWithProviders(
        <ul>
            <UpcomingEventRow
                event={{
                    kind: 'event',
                    id: 5,
                    name: 'Last walk',
                    openDate: '2026-12-31',
                    participantCount: 0,
                    commentCount: 0,
                    bumpedAt: '2026-12-01T00:00:00Z',
                    group: { id: 1, name: 'Hikers', imageUrl: null },
                }}
            />
        </ul>,
    );
    const date = () => screen.getByRole('link', { name: /Last walk/ }).querySelector('time')?.textContent;
    expect(date()).toBe('Thu, Dec 31');

    midnight();

    expect(date()).toBe('Thu, Dec 31, 2026');
});
