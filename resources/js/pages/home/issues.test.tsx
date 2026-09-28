import { cleanup, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import HomeIssues from './issues';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
import type { DayCounts, DaySummary, DayTop, MonthRef } from './types';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

const inertia = vi.hoisted(() => ({ page: {} as { component: string; url: string; props: Record<string, unknown> } }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => inertia.page,
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    Head: () => null,
}));

afterEach(cleanup);

const month = (year: number, number: number): MonthRef => ({
    year,
    month: number,
    href: `/home/${year}/${String(number).padStart(2, '0')}`,
});

const NOTHING: DayCounts = { stories: 0, responses: 0, talk: 0, newcomers: 0, newGroups: 0 };

const day = (date: string, top: DayTop | null, counts: Partial<DayCounts> = {}, from = date): DaySummary => ({
    date,
    number: 1,
    href: `/home/${date.replaceAll('-', '/')}`,
    days: { from, to: date },
    counts: { ...NOTHING, ...counts },
    level: top === null ? 0 : 1,
    top,
});

const member = { id: 7, name: 'Hanako', imageUrl: null, avatarColor: null, isAi: false };
const group = { id: 3, name: 'Hikers', imageUrl: null };

function arrive(props: Record<string, unknown>) {
    inertia.page = {
        component: 'home/issues',
        url: '/home/2026/08',
        props: { locale: 'en', timezone: 'Asia/Tokyo', month: month(2026, 8), prev: null, next: null, days: [], months: [], ...props },
    };

    return renderWithProviders(<HomeIssues />);
}

/** A row of the month, not an entry of the counts list inside one. */
function row(index: number): HTMLElement {
    const found = screen.getAllByRole('list')[0]?.children[index];

    if (!(found instanceof HTMLElement)) {
        throw new Error(`no row ${index}`);
    }

    return found;
}

test('a day is called by its date, which is the one link of its row', () => {
    arrive({
        days: [
            day('2026-08-27', { kind: 'story', headline: 'Morning walk', image: null }, { stories: 2, responses: 5 }),
            day('2026-08-26', { kind: 'story', headline: 'Evening run', image: null }, { stories: 1 }),
        ],
    });

    // getByRole, so a second link in the row would fail the query.
    const latest = within(row(0)).getByRole('link');
    expect(latest.textContent).toBe('Thu, August 27, 2026');
    expect(latest.getAttribute('href')).toBe('/home/2026/08/27');
    expect(within(row(0)).getByText('Morning walk')).toBeTruthy();

    expect(within(row(1)).getByRole('link').getAttribute('href')).toBe('/home/2026/08/26');
});

test('the counts say what happened and leave out what did not', () => {
    arrive({
        days: [
            day(
                '2026-08-27',
                { kind: 'story', headline: 'Morning walk', image: null },
                { stories: 4, responses: 1, talk: 35, newcomers: 1 },
            ),
        ],
    });

    expect(
        within(row(0))
            .getAllByRole('listitem')
            .map((item) => item.textContent),
    ).toEqual(['4 stories', '1 response', '35 talk messages', '1 new member']);
});

test('a stretch of days is named as one', () => {
    arrive({ days: [day('2026-08-27', { kind: 'story', headline: 'Morning walk', image: null }, { stories: 1 }, '2026-08-21')] });

    expect(screen.getByRole('link', { name: 'August 21, 2026 to August 27, 2026' })).toBeTruthy();
});

test('a day with no story leads with what it does have', () => {
    arrive({
        days: [
            day('2026-08-27', { kind: 'talk', group }, { talk: 12 }),
            day('2026-08-26', { kind: 'newcomer', member, others: 0 }, { newcomers: 1 }),
            day('2026-08-25', { kind: 'newcomer', member, others: 1 }, { newcomers: 2 }),
            day('2026-08-24', { kind: 'newcomer', member, others: 3 }, { newcomers: 4 }),
            day('2026-08-23', { kind: 'newGroup', group }, { newGroups: 1 }),
        ],
    });

    expect(within(row(0)).getByText('Talk in Hikers')).toBeTruthy();
    expect(within(row(1)).getByText('Hanako joined')).toBeTruthy();
    expect(within(row(2)).getByText('Hanako and 1 other joined')).toBeTruthy();
    expect(within(row(3)).getByText('Hanako and 3 others joined')).toBeTruthy();
    expect(within(row(4)).getByText('New %community%: Hikers')).toBeTruthy();
});

test('an AI newcomer is named as one', () => {
    arrive({ days: [day('2026-08-27', { kind: 'newcomer', member: { ...member, isAi: true }, others: 0 }, { newcomers: 1 })] });

    expect(screen.getByText('Hanako (AI) joined')).toBeTruthy();
});

test('a day with nothing left is its date alone', () => {
    arrive({ days: [day('2026-08-27', null)] });

    expect(within(row(0)).getByRole('link', { name: 'Thu, August 27, 2026' })).toBeTruthy();
    expect(within(row(0)).getByRole('heading', { level: 3, name: 'Thu, August 27, 2026' })).toBeTruthy();
    expect(row(0).querySelector('p, ul, img')).toBeNull();
});

test('the pager offers only the months there is one to go to', () => {
    arrive({ prev: month(2026, 6), next: month(2026, 9) });

    expect(screen.getByRole('heading', { level: 2, name: 'August 2026' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Earlier month June 2026' }).getAttribute('href')).toBe('/home/2026/06');
    expect(screen.getByRole('link', { name: 'Later month September 2026' }).getAttribute('href')).toBe('/home/2026/09');

    cleanup();

    arrive({});

    expect(screen.queryByText('Earlier month')).toBeNull();
    expect(screen.queryByText('Later month')).toBeNull();
});

test('a month with no day in it says so instead of drawing an empty list', () => {
    arrive({ prev: month(2026, 6), next: month(2026, 9) });

    // The calendar is still drawn: an empty month is a month, with no day to follow.
    expect(screen.getByRole('table', { name: 'August 2026' })).toBeTruthy();
    expect(screen.queryAllByRole('link').map((link) => link.getAttribute('href'))).toEqual(['/home/2026/06', '/home/2026/09']);
    expect(screen.getByText('Nothing happened this month.')).toBeTruthy();
    expect(screen.queryByRole('list')).toBeNull();
});

test('a site that has published nothing has no month to show', () => {
    arrive({ month: null });

    expect(screen.getByText('Nothing yet.')).toBeTruthy();
    expect(screen.queryByRole('navigation')).toBeNull();
    expect(screen.queryByRole('table')).toBeNull();
});

test('every month that holds an issue can be jumped to, and the one on screen is marked', () => {
    arrive({
        days: [day('2026-08-27', { kind: 'story', headline: 'Morning walk', image: null }, { stories: 1 })],
        months: [
            { year: 2026, month: 9, count: 22, href: '/home/2026/09' },
            { year: 2026, month: 8, count: 11, href: '/home/2026/08' },
            { year: 2025, month: 12, count: 1, href: '/home/2025/12' },
        ],
    });

    expect(screen.getByRole('link', { name: 'September 2026, 22 days of happenings' }).getAttribute('href')).toBe('/home/2026/09');
    expect(screen.getByRole('link', { name: 'December 2025, 1 day of happenings' }).getAttribute('href')).toBe('/home/2025/12');
    expect(screen.getByRole('link', { name: 'August 2026, 11 days of happenings' }).getAttribute('aria-current')).toBe('true');
    // A month that holds none is a label and nothing to follow.
    expect(screen.getByLabelText('July 2026').tagName).toBe('SPAN');
});
