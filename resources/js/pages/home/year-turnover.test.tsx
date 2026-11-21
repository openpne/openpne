import { act, cleanup, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { DayBlock } from './day-block';
import { DayCalendar } from './day-calendar';
import { UpcomingEventRow } from './event-row';
import { IssueNav } from './issue-nav';
import { Masthead } from './masthead';
import { MonthCalendar } from './month-calendar';
import type { DaySummary } from './types';
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
    const date = () => screen.getByRole('link', { name: /Last walk/ }).closest('li')?.querySelector('time')?.textContent;
    expect(date()).toBe('Thu, Dec 31');

    midnight();

    expect(date()).toBe('Thu, Dec 31, 2026');
});

const lastDay: DaySummary = {
    date: '2026-12-30',
    number: 9,
    href: '/home/2026/12/30',
    bornDays: { from: '2026-12-30', to: '2026-12-30' },
    items: [{ kind: 'diary', href: '/diary/1', headline: 'Last walk', responses: 0, groupName: null }],
    more: 0,
    newcomers: [],
    newGroups: [],
};

test("a month's day left open into the new year takes the old year on", () => {
    renderWithProviders(
        <ul>
            <DayBlock day={lastDay} />
        </ul>,
    );
    const heading = () => screen.getByRole('heading', { level: 3 }).textContent;
    expect(heading()).toBe('Wed, Dec 30');

    midnight();

    expect(heading()).toBe('Wed, Dec 30, 2026');
});

test("a month's calendar left open into the new year takes the old year on, and lets go of today", () => {
    renderWithProviders(<MonthCalendar month={{ year: 2026, month: 12, href: '/home/2026/12' }} days={[lastDay]} />);
    const link = () => screen.getByRole('link').getAttribute('aria-label');
    expect(link()).toBe('Wed, Dec 30, Last walk');
    expect(screen.getByText('Today').parentElement?.textContent).toBe('31Today');

    midnight();

    expect(link()).toBe('Wed, Dec 30, 2026, Last walk');
    // The 1st is in January's page, so December has no today left.
    expect(screen.queryByText('Today')).toBeNull();
});

test("a past day's band left open into the new year takes the old year on", () => {
    renderWithProviders(
        <Masthead
            days={{ from: '2026-12-30', to: '2026-12-30' }}
            prev={null}
            next={null}
            calendar={{ date: '2026-12-30', monthHref: '/home/2026/12', days: [] }}
        />,
    );
    const band = () => screen.getByRole('button').querySelector('time')?.textContent;
    expect(band()).toBe('Wed, Dec 30');

    midnight();

    expect(band()).toBe('Wed, Dec 30, 2026');
});

test("a past day's month left open into the new year takes the old year on, and lets go of today", () => {
    renderWithProviders(
        <DayCalendar id="month" date="2026-12-30" monthHref="/home/2026/12" days={[{ date: '2026-12-30', number: 9, href: '/home/2026/12/30' }]} />,
    );
    const day = () => screen.getByRole('link', { name: /Dec 30/ }).getAttribute('aria-label');
    const foot = () => screen.getByRole('link', { name: /at a glance/ }).textContent;
    expect(day()).toBe('Wed, Dec 30');
    expect(foot()).toBe('Dec at a glance');
    expect(screen.getByText('Today').parentElement?.textContent).toBe('31Today');

    midnight();

    expect(day()).toBe('Wed, Dec 30, 2026');
    expect(foot()).toBe('December 2026 at a glance');
    expect(screen.queryByText('Today')).toBeNull();
});
