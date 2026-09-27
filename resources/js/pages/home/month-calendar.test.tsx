import { cleanup, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { MonthCalendar } from './month-calendar';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
import type { DaySummary, DayStory, DayTalk } from './types';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'en', timezone: 'Asia/Tokyo' } }),
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

const picture = {
    id: 9,
    url: '/cache/img/9.png',
    thumbnailUrl: '/cache/img/9-t.png',
    fitSources: [{ url: '/cache/img/9-320.png', box: 320 }],
    cropSources: {},
    width: 640,
    height: 480,
    animatedSources: [],
};

const story = (id: number, headline: string, rest: Partial<DayStory> = {}): DayStory => ({
    kind: 'story',
    href: `/diary/${id}`,
    headline,
    responses: 0,
    image: null,
    ...rest,
});

const talk = (rest: Partial<DayTalk> = {}): DayTalk => ({
    kind: 'talk',
    href: '/groups/3/talk?m=40',
    group: { id: 3, name: 'Hikers', imageUrl: '/cache/img/group.png' },
    speaker: { name: 'Hanako', isAi: false },
    line: 'Photos are up',
    count: 23,
    image: null,
    ...rest,
});

const day = (date: string, rest: Partial<DaySummary> = {}, from = date): DaySummary => ({
    date,
    number: 1,
    href: `/home/${date.replaceAll('-', '/')}`,
    days: { from, to: date },
    items: [story(1, 'Morning walk')],
    more: 0,
    newcomers: [],
    newGroups: [],
    ...rest,
});

const august = { year: 2026, month: 8, href: '/home/2026/08' };

/** The cell a day stands in, found by the number it opens with. */
function cell(number: number): HTMLElement {
    const found = screen.getAllByRole('cell').find((candidate) => candidate.textContent?.match(/^\d+/)?.[0] === String(number));

    if (found === undefined) {
        throw new Error(`no cell ${number}`);
    }

    return found;
}

/** What a cell prints under the line its date stands on, a line each. */
const printed = (number: number): string[] =>
    [...within(cell(number)).getByRole('link').children].slice(1).map((line) => line.textContent ?? '');

/** The line a date stands on, and what shares it. */
const top = (number: number): string => within(cell(number)).getByRole('link').children[0]?.textContent ?? '';

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

    expect(cell(26).textContent).toBe('26');
    expect(within(cell(26)).queryByRole('link')).toBeNull();
    expect(screen.getAllByRole('link')).toHaveLength(1);
});

test('a day prints what its block lists, a line each and in its order', () => {
    renderWithProviders(
        <MonthCalendar month={august} days={[day('2026-08-27', { items: [story(1, 'Morning walk'), talk(), story(2, 'Evening run')] })]} />,
    );

    expect(printed(27)).toEqual(['Morning walk', 'Hikers', 'Evening run']);

    const link = within(cell(27)).getByRole('link', {
        name: 'Thu, August 27, 2026, Morning walk, Hanako: Photos are up, Evening run',
    });

    // To the day's block in this page, which is where the day is read.
    expect(link.getAttribute('href')).toBe('#day-2026-08-27');
});

test('a line is cut where the cell ends and never wrapped', () => {
    renderWithProviders(<MonthCalendar month={august} days={[day('2026-08-27')]} />);

    const [line] = [...within(cell(27)).getByRole('link').children].slice(1);

    expect(line?.className).toContain('whitespace-nowrap');
    expect(line?.className).toContain('overflow-hidden');
});

test('a room is printed by its name and said in full by the link', () => {
    renderWithProviders(
        <MonthCalendar month={august} days={[day('2026-08-27', { items: [talk()] }), day('2026-08-26', { items: [talk({ line: '' })] })]} />,
    );

    expect(printed(27)).toEqual(['Hikers']);
    expect(within(cell(27)).getByRole('link', { name: 'Thu, August 27, 2026, Hanako: Photos are up' })).toBeTruthy();
    expect(within(cell(26)).getByRole('link', { name: 'Wed, August 26, 2026, Hikers' })).toBeTruthy();
});

test('a story and a room are told apart by how their lines are drawn', () => {
    renderWithProviders(<MonthCalendar month={august} days={[day('2026-08-27', { items: [story(1, 'Morning walk'), talk()] })]} />);

    const [first, second] = [...within(cell(27)).getByRole('link').children].slice(1);

    expect(first?.className).not.toBe(second?.className);
});

test('what the block does not list is counted', () => {
    renderWithProviders(
        <MonthCalendar month={august} days={[day('2026-08-27', { more: 5 }), day('2026-08-26', { more: 1 }), day('2026-08-25')]} />,
    );

    expect(printed(27)).toEqual(['Morning walk']);
    expect(top(27)).toBe('27+5');
    expect(top(25)).toBe('25');
    expect(within(cell(27)).getByRole('link', { name: 'Thu, August 27, 2026, Morning walk, 5 more' })).toBeTruthy();
    expect(within(cell(26)).getByRole('link', { name: 'Wed, August 26, 2026, Morning walk, 1 more' })).toBeTruthy();
    expect(printed(25)).toEqual(['Morning walk']);
});

test('names take the room the items leave, and none when there is none', () => {
    const names = {
        newcomers: [{ id: 8, name: 'Robo', isAi: true, href: '/member/8' }],
        newGroups: [
            { id: 3, name: 'Hikers', href: '/groups/3' },
            { id: 4, name: 'Readers', href: '/groups/4' },
        ],
    };

    renderWithProviders(
        <MonthCalendar
            month={august}
            days={[
                day('2026-08-27', { items: [], ...names }),
                day('2026-08-26', { ...names }),
                day('2026-08-25', { items: [story(1, 'One'), story(2, 'Two'), story(3, 'Three')], ...names }),
            ]}
        />,
    );

    expect(printed(27)).toEqual(['Robo (AI)', 'Hikers', 'Readers']);
    expect(printed(26)).toEqual(['Morning walk', 'Robo (AI)', 'Hikers']);
    expect(printed(25)).toEqual(['One', 'Two', 'Three']);
});

test('a cell draws no picture, whatever its day holds', () => {
    renderWithProviders(
        <MonthCalendar month={august} days={[day('2026-08-27', { items: [story(1, 'Morning walk', { image: picture }), talk({ image: picture })] })]} />,
    );

    expect(cell(27).querySelector('img')).toBeNull();
});

test('a day with nothing left is still a link, named by its date alone', () => {
    renderWithProviders(<MonthCalendar month={august} days={[day('2026-08-27', { items: [] })]} />);

    expect(within(cell(27)).getByRole('link', { name: 'Thu, August 27, 2026' }).textContent).toBe('27');
});

test('an issue covering a stretch of days stands on the day it is dated', () => {
    renderWithProviders(<MonthCalendar month={august} days={[day('2026-08-27', {}, '2026-08-21')]} />);

    // Named as the block below names it, so the stretch is heard as one in both places.
    expect(within(cell(27)).getByRole('link', { name: 'August 21, 2026 to August 27, 2026, Morning walk' })).toBeTruthy();
    expect(within(cell(21)).queryByRole('link')).toBeNull();
    expect(screen.getAllByRole('link')).toHaveLength(1);
});

test('today is marked on the site\'s calendar, whether or not it has an issue', () => {
    // 00:30 on the 28th in Tokyo, which is still the 27th in UTC.
    vi.useFakeTimers({ now: new Date('2026-08-27T15:30:00Z') });

    renderWithProviders(<MonthCalendar month={august} days={[day('2026-08-27')]} />);

    expect(cell(28).textContent).toBe('28Today');
    expect(within(cell(27)).queryByText('Today')).toBeNull();
    expect(screen.getAllByText('Today')).toHaveLength(1);
});

test('the weekend is told apart from the week, in the heading and in the dates', () => {
    renderWithProviders(<MonthCalendar month={august} days={[day('2026-08-02'), day('2026-08-08')]} />);

    const tones = (element: Element | null | undefined): string[] =>
        (element?.className ?? '').split(' ').filter((name) => name === 'text-sunday' || name === 'text-saturday');

    expect(screen.getAllByRole('columnheader').map(tones)).toEqual([['text-sunday'], [], [], [], [], [], ['text-saturday']]);
    // August 2026: the 2nd is a Sunday and the 8th a Saturday, the 3rd a Monday with no issue.
    expect(tones(cell(2).querySelector('a > span > span'))).toEqual(['text-sunday']);
    expect(tones(cell(8).querySelector('a > span > span'))).toEqual(['text-saturday']);
    expect(tones(cell(3).querySelector('span > span > span'))).toEqual([]);
    expect(tones(cell(9).querySelector('span > span > span'))).toEqual(['text-sunday']);
});

test('a week nothing happened in is drawn as a line of dates', () => {
    renderWithProviders(<MonthCalendar month={august} days={[day('2026-08-27')]} />);

    const heights = (number: number): string[] => cell(number).className.split(' ').filter((name) => /^(sm:)?h-/.test(name));

    // The 27th's week is as tall for the days beside it as for the day itself.
    expect(heights(27)).toEqual(heights(23));
    expect(heights(27)).not.toEqual(heights(10));
    expect(heights(10)).toEqual(['h-8']);
});

test('another month marks no day as today', () => {
    vi.useFakeTimers({ now: new Date('2026-09-10T03:00:00Z') });

    renderWithProviders(<MonthCalendar month={august} days={[day('2026-08-10')]} />);

    expect(screen.queryByText('Today')).toBeNull();
});
