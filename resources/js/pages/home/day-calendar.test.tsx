import { cleanup, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { DayCalendar } from './day-calendar';
import ja from '../../../../lang/ja.json';
import { renderWithProviders } from '@/lib/test-render';

const translate = (key: string, replacements: Record<string, string | number> = {}): string =>
    Object.entries(replacements).reduce((line, [name, value]) => line.replaceAll(`:${name}`, String(value)), (ja as Record<string, string>)[key] ?? key);

vi.mock('@/lib/i18n', () => ({ useT: () => translate }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'ja', timezone: 'Asia/Tokyo' } }),
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

beforeEach(() => {
    vi.useFakeTimers();
    // Noon on the 28th in Tokyo.
    vi.setSystemTime(new Date('2026-08-28T03:00:00Z'));
});

afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

const issue = (date: string, number: number) => ({ date, number, href: `/home/${date.replaceAll('-', '/')}` });

test('a day with an issue is a link to it, the day being read marked as current, and any other day is its date alone', () => {
    renderWithProviders(<DayCalendar id="c" date="2026-08-20" days={[issue('2026-08-28', 13), issue('2026-08-20', 12), issue('2026-08-03', 1)]} monthHref="/home/2026/08" />);

    const links = screen.getAllByRole('link').filter((link) => link.getAttribute('href')?.startsWith('/home/2026/08/'));
    expect(links.map((link) => link.getAttribute('href'))).toEqual(['/home/2026/08/03', '/home/2026/08/20', '/home/2026/08/28']);

    const current = screen.getByRole('link', { name: '8月20日(木)' });
    expect(current.getAttribute('aria-current')).toBe('date');
    expect(screen.getByRole('link', { name: '8月3日(月)' }).hasAttribute('aria-current')).toBe(false);
    // Today is said, not only drawn.
    expect(screen.getByRole('link', { name: '8月28日(金), 今日' })).toBeTruthy();

    expect(screen.getByRole('cell', { name: '19' }).querySelector('a')).toBeNull();
});

test('today without an issue is said as well', () => {
    renderWithProviders(<DayCalendar id="c" date="2026-08-20" days={[issue('2026-08-20', 12)]} monthHref="/home/2026/08" />);

    expect(screen.getByRole('cell', { name: '28 今日' }).querySelector('a')).toBeNull();
});

test('the foot leads to the month page, the year named only when it is not this one', () => {
    renderWithProviders(<DayCalendar id="c" date="2026-08-20" days={[issue('2026-08-20', 12)]} monthHref="/home/2026/08" />);
    expect(screen.getByRole('link', { name: '8月をまとめて見る' }).getAttribute('href')).toBe('/home/2026/08');

    cleanup();

    renderWithProviders(<DayCalendar id="c" date="2025-12-20" days={[issue('2025-12-20', 2)]} monthHref="/home/2025/12" />);
    expect(screen.getByRole('link', { name: '2025年12月をまとめて見る' }).getAttribute('href')).toBe('/home/2025/12');
    // The caption names the table with its year either way.
    expect(screen.getByRole('table', { name: '2025年12月' })).toBeTruthy();
});
