import { cleanup, fireEvent, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { MonthGrid } from './month-grid';
import { fakeT } from '@/lib/test-i18n';
import { buildMonthRows, type MonthlyCount } from '@/lib/month-grid';
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

const href = (year: number, month: number): string => `/somewhere/${year}/${month}`;

function draw(counts: MonthlyCount[], selected: { year: number; month: number } | null = null, footer?: ReactNode) {
    return renderWithProviders(
        <MonthGrid
            rows={buildMonthRows(counts, 2026, href, selected)}
            selected={selected}
            bucket={(count) => (count > 5 ? 4 : 1)}
            countPhrase={(count) => `${count} things`}
            title="Months"
            footer={footer}
        />,
    );
}

test('nothing to count draws nothing at all', () => {
    const { container } = draw([]);

    expect(container.textContent).toBe('');
});

test('a month with a count is a link named by it, and a month without is not a link', () => {
    draw([{ year: 2026, month: 9, count: 3 }]);

    const september = screen.getByRole('link', { name: 'September 2026, 3 things' });

    expect(september.getAttribute('href')).toBe('/somewhere/2026/9');
    expect(screen.getAllByRole('link')).toHaveLength(1);
    expect(screen.getByLabelText('August 2026').tagName).toBe('SPAN');
});

/** A plain space collapses to no line at all, and the label of an empty month then sits lower than its neighbours'. */
test('an empty month still holds the line its count would stand on', () => {
    draw([{ year: 2026, month: 9, count: 3 }]);

    const lines = (cell: HTMLElement): (string | null)[] => Array.from(cell.children).map((line) => line.textContent);

    expect(lines(screen.getByLabelText('August 2026'))).toEqual(['Aug', ' ']);
    expect(lines(screen.getByRole('link', { name: 'September 2026, 3 things' }))).toEqual(['Sep', '3']);
});

test('the caller says how heavily a count fills its cell', () => {
    draw([
        { year: 2026, month: 8, count: 2 },
        { year: 2026, month: 9, count: 9 },
    ]);

    expect(screen.getByRole('link', { name: 'August 2026, 2 things' }).className).toContain('bg-selected/10');
    expect(screen.getByRole('link', { name: 'September 2026, 9 things' }).className).toContain('bg-selected/45');
});

test('the month the reader is on is marked, linked or not', () => {
    draw([{ year: 2026, month: 9, count: 3 }], { year: 2026, month: 9 });
    expect(screen.getByRole('link', { name: 'September 2026, 3 things' }).getAttribute('aria-current')).toBe('true');

    cleanup();

    draw([{ year: 2026, month: 9, count: 3 }], { year: 2026, month: 7 });
    expect(screen.getByLabelText('July 2026').getAttribute('aria-current')).toBe('true');
    expect(screen.getByRole('link', { name: 'September 2026, 3 things' }).getAttribute('aria-current')).toBeNull();
});

test('years before the last two are folded until asked for', () => {
    draw([
        { year: 2026, month: 9, count: 3 },
        { year: 2023, month: 4, count: 1 },
    ]);

    expect(screen.queryByRole('link', { name: 'April 2023, 1 things' })).toBeNull();

    const toggle = screen.getByRole('button', { name: 'Show earlier years' });
    expect(toggle.getAttribute('aria-expanded')).toBe('false');

    fireEvent.click(toggle);

    expect(screen.getByRole('link', { name: 'April 2023, 1 things' })).toBeTruthy();
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
});

test('a reader on a folded year finds it open, with nothing to fold it by', () => {
    draw(
        [
            { year: 2026, month: 9, count: 3 },
            { year: 2023, month: 4, count: 1 },
        ],
        { year: 2023, month: 4 },
    );

    expect(screen.getByRole('link', { name: 'April 2023, 1 things' }).getAttribute('aria-current')).toBe('true');
    expect(screen.queryByRole('button', { name: 'Show earlier years' })).toBeNull();
});

test('the footer is drawn when it is given', () => {
    draw([{ year: 2026, month: 9, count: 3 }], null, <a href="/all">Everything</a>);

    expect(screen.getByRole('link', { name: 'Everything' })).toBeTruthy();
});
