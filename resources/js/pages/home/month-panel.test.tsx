import { cleanup, fireEvent, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { MonthPanel } from './month-panel';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
import type { MonthRef } from './types';

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

const month = (year: number, number: number): MonthRef => ({
    year,
    month: number,
    href: `/home/${year}/${String(number).padStart(2, '0')}`,
});

const listed = (year: number, number: number, count: number) => ({ ...month(year, number), count });

const MONTHS = [listed(2026, 9, 22), listed(2026, 8, 11), listed(2025, 12, 1)];

function panel(props: Partial<Parameters<typeof MonthPanel>[0]> = {}) {
    return renderWithProviders(<MonthPanel month={month(2026, 8)} prev={null} next={null} days={[]} months={MONTHS} {...props} />);
}

const hrefs = (): (string | null)[] => screen.queryAllByRole('link').map((link) => link.getAttribute('href'));

test('the month is named by a heading that opens the grid of months', () => {
    panel();

    const toggle = screen.getByRole('button', { name: 'August 2026 Jump to a month' });

    expect(screen.getByRole('heading', { level: 2 }).contains(toggle)).toBe(true);
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    // Closed, the grid is not drawn: no month is a link and nothing carries the id the button names.
    expect(hrefs()).toEqual([]);
    expect(document.getElementById(toggle.getAttribute('aria-controls') ?? '')).toBeNull();
});

test('opened, every month that holds an issue can be gone to and the one on screen is marked', () => {
    panel();

    const toggle = screen.getByRole('button', { name: 'August 2026 Jump to a month' });
    fireEvent.click(toggle);

    expect(toggle.getAttribute('aria-expanded')).toBe('true');

    const grid = document.getElementById(toggle.getAttribute('aria-controls') ?? '');
    expect(grid).not.toBeNull();
    expect(grid?.contains(screen.getByRole('link', { name: 'September 2026, 22 days of happenings' }))).toBe(true);
    expect(screen.getByRole('link', { name: 'December 2025, 1 day of happenings' }).getAttribute('href')).toBe('/home/2025/12');
    expect(screen.getByRole('link', { name: 'August 2026, 11 days of happenings' }).getAttribute('aria-current')).toBe('true');
    // A month that holds none is a label and nothing to follow.
    expect(screen.getByLabelText('July 2026').tagName).toBe('SPAN');

    fireEvent.click(toggle);

    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(hrefs()).toEqual([]);
});

test('the grid is drawn above the calendar, inside the panel that holds both', () => {
    panel();

    fireEvent.click(screen.getByRole('button', { name: 'August 2026 Jump to a month' }));

    const grid = screen.getByRole('link', { name: 'September 2026, 22 days of happenings' });
    const calendar = screen.getByRole('table', { name: 'August 2026' });

    expect(grid.compareDocumentPosition(calendar) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
});

test('a site with one month to its name has nothing to open', () => {
    panel({ months: [listed(2026, 8, 11)] });

    expect(screen.getByRole('heading', { level: 2, name: 'August 2026' })).toBeTruthy();
    expect(screen.queryByRole('button')).toBeNull();
});

test('the months either side are named without the year they share with this one', () => {
    panel({ prev: month(2026, 6), next: month(2026, 9) });

    expect(screen.getByRole('link', { name: 'Earlier month Jun' }).getAttribute('href')).toBe('/home/2026/06');
    expect(screen.getByRole('link', { name: 'Later month Sep' }).getAttribute('href')).toBe('/home/2026/09');
});

test('a month of another year says which year', () => {
    panel({ month: month(2026, 1), prev: month(2025, 12), next: month(2026, 2) });

    expect(screen.getByRole('link', { name: 'Earlier month December 2025' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Later month Feb' })).toBeTruthy();
});
