import { cleanup, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { MonthCalendar } from './month-calendar';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
import type { DayItem, DaySummary } from './types';

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

const story: DayItem = { kind: 'story', href: '/diary/1', headline: 'Morning walk', responses: 2, image: null };

const day = (date: string, items: DayItem[] = [story], from = date): DaySummary => ({
    date,
    number: 1,
    href: `/home/${date.replaceAll('-', '/')}`,
    days: { from, to: date },
    items,
    more: 0,
    newcomers: [],
    newGroups: [],
});

const august = { year: 2026, month: 8, href: '/home/2026/08' };

/** The cell a day number stands in, which is where its link is or is not. */
function cell(number: number): HTMLElement {
    const found = screen.getAllByRole('cell').find((candidate) => candidate.textContent === String(number));

    if (found === undefined) {
        throw new Error(`no cell ${number}`);
    }

    return found;
}

test('the month is a table of weeks under the days of the week', () => {
    renderWithProviders(<MonthCalendar month={august} days={[]} />);

    expect(screen.getByRole('table', { name: 'August 2026' })).toBeTruthy();
    expect(screen.getAllByRole('columnheader').map((header) => header.textContent)).toEqual([
        'Sun',
        'Mon',
        'Tue',
        'Wed',
        'Thu',
        'Fri',
        'Sat',
    ]);
    // August 2026 opens on a Saturday: six weeks, and every day of the month in one of them.
    expect(screen.getAllByRole('row')).toHaveLength(7);
    expect(cell(1)).toBeTruthy();
    expect(cell(31)).toBeTruthy();
});

test('a day with no issue is a number and nothing to follow', () => {
    renderWithProviders(<MonthCalendar month={august} days={[day('2026-08-27')]} />);

    expect(within(cell(26)).queryByRole('link')).toBeNull();
    expect(screen.getAllByRole('link')).toHaveLength(1);
});

test('a day with an issue is a bordered link named by its date', () => {
    renderWithProviders(<MonthCalendar month={august} days={[day('2026-08-27')]} />);

    const link = within(cell(27)).getByRole('link', { name: 'Thu, August 27, 2026' });

    expect(link.getAttribute('href')).toBe('/home/2026/08/27');
    expect(link.className).toContain('border');
});

test('a day is drawn the same however much happened on it', () => {
    renderWithProviders(<MonthCalendar month={august} days={[day('2026-08-02', [story, story, story]), day('2026-08-01', [])]} />);

    expect(within(cell(1)).getByRole('link').className).toBe(within(cell(2)).getByRole('link').className);
});

test('an issue covering a stretch of days stands on the day it is dated', () => {
    renderWithProviders(<MonthCalendar month={august} days={[day('2026-08-27', [story], '2026-08-21')]} />);

    // Named as the block below names it, so the stretch is heard as one in both places.
    expect(within(cell(27)).getByRole('link', { name: 'August 21, 2026 to August 27, 2026' })).toBeTruthy();
    expect(within(cell(21)).queryByRole('link')).toBeNull();
    expect(screen.getAllByRole('link')).toHaveLength(1);
});
