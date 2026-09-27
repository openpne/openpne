import { cleanup, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { HeatCalendar } from './heat-calendar';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
import type { DayCounts, DaySummary } from './types';

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

const NOTHING: DayCounts = { stories: 0, responses: 0, talk: 0, newcomers: 0, newGroups: 0 };

const day = (date: string, level: DaySummary['level'], counts: Partial<DayCounts> = {}, from = date): DaySummary => ({
    date,
    number: 1,
    href: `/home/${date.replaceAll('-', '/')}`,
    days: { from, to: date },
    counts: { ...NOTHING, ...counts },
    level,
    top: null,
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
    renderWithProviders(<HeatCalendar month={august} days={[]} />);

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
    renderWithProviders(<HeatCalendar month={august} days={[day('2026-08-27', 2, { stories: 1 })]} />);

    expect(within(cell(26)).queryByRole('link')).toBeNull();
    expect(screen.getAllByRole('link')).toHaveLength(1);
});

test('a day with an issue is a link that says what happened on it', () => {
    renderWithProviders(
        <HeatCalendar month={august} days={[day('2026-08-27', 3, { stories: 4, responses: 12, talk: 35 })]} />,
    );

    const link = within(cell(27)).getByRole('link', {
        name: 'Thu, August 27, 2026, 4 stories, 12 responses, 35 talk messages',
    });

    expect(link.getAttribute('href')).toBe('/home/2026/08/27');
    expect(link.className).toContain('bg-selected/30');
    expect(link.className).toContain('border');
});

test('each level is drawn in a fill of its own', () => {
    renderWithProviders(
        <HeatCalendar
            month={august}
            days={[
                day('2026-08-04', 4, { stories: 9 }),
                day('2026-08-03', 3, { stories: 5 }),
                day('2026-08-02', 2, { stories: 3 }),
                day('2026-08-01', 1, { stories: 1 }),
            ]}
        />,
    );

    const fills = [1, 2, 3, 4].map(
        (number) =>
            within(cell(number))
                .getByRole('link')
                .className.split(' ')
                .find((name) => name.startsWith('bg-selected/')) ?? null,
    );

    expect(fills).toEqual(['bg-selected/10', 'bg-selected/20', 'bg-selected/30', 'bg-selected/45']);
});

test('a day with nothing left is still a link, named by its date and drawn with no fill', () => {
    renderWithProviders(<HeatCalendar month={august} days={[day('2026-08-27', 0)]} />);

    const link = within(cell(27)).getByRole('link', { name: 'Thu, August 27, 2026' });

    expect(link.className).toContain('border');
    expect(link.className.split(' ').some((name) => name.startsWith('bg-selected/'))).toBe(false);
});

test('an issue covering a stretch of days stands on the day it is dated', () => {
    renderWithProviders(<HeatCalendar month={august} days={[day('2026-08-27', 1, { stories: 1 }, '2026-08-21')]} />);

    expect(within(cell(27)).getByRole('link')).toBeTruthy();
    expect(within(cell(21)).queryByRole('link')).toBeNull();
    expect(screen.getAllByRole('link')).toHaveLength(1);
});

test('the legend says the shading compares the days of this month', () => {
    renderWithProviders(<HeatCalendar month={august} days={[]} />);

    expect(screen.getByText('How busy each day was, within this month')).toBeTruthy();
    expect(screen.getByText('Quieter')).toBeTruthy();
    expect(screen.getByText('Busier')).toBeTruthy();
});
