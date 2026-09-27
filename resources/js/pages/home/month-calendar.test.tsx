import { cleanup, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { MonthCalendar } from './month-calendar';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
import type { DayItem, DaySummary, DayTalk } from './types';

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

const story: DayItem = { kind: 'story', href: '/diary/1', headline: 'Morning walk', responses: 2, image: null };

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
    items: [story],
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

test('a day opens with its date and the words its block opens with', () => {
    renderWithProviders(<MonthCalendar month={august} days={[day('2026-08-27')]} />);

    const link = within(cell(27)).getByRole('link', { name: 'Thu, August 27, 2026, Morning walk' });

    expect(link.getAttribute('href')).toBe('/home/2026/08/27');
    expect(link.textContent).toBe('27Morning walk');
    expect(link.querySelector('img')).toBeNull();
});

test('a day whose block opens with a picture is drawn as the picture under its date', () => {
    renderWithProviders(<MonthCalendar month={august} days={[day('2026-08-27', { items: [{ ...story, image: picture }] })]} />);

    const link = within(cell(27)).getByRole('link', { name: 'Thu, August 27, 2026, Morning walk' });

    // The block's own picture, asked for the way the block asks for it, so it is fetched once.
    expect(link.querySelector('img')?.getAttribute('src')).toBe('/cache/img/9-320.png');
    expect(link.querySelector('img')?.getAttribute('sizes')).toBe('4rem');
    expect(link.textContent).toBe('27');
});

test('a day that opens with a room says what was last said in it', () => {
    renderWithProviders(
        <MonthCalendar
            month={august}
            days={[
                day('2026-08-27', { items: [talk({ image: picture }), story] }),
                day('2026-08-26', { items: [talk()] }),
                day('2026-08-25', { items: [talk({ line: '' })] }),
            ]}
        />,
    );

    expect(within(cell(27)).getByRole('link', { name: 'Thu, August 27, 2026, Hanako: Photos are up' }).querySelector('img')).not.toBeNull();
    expect(within(cell(26)).getByRole('link').textContent).toBe('26Hanako: Photos are up');
    // Never the group's image, which the block draws as a mark and not as a picture.
    expect(within(cell(26)).getByRole('link').querySelector('img')).toBeNull();
    expect(within(cell(25)).getByRole('link', { name: 'Tue, August 25, 2026, Hikers' }).textContent).toBe('25Hikers');
});

test('a day of names alone opens with the first of them', () => {
    renderWithProviders(
        <MonthCalendar
            month={august}
            days={[
                day('2026-08-27', {
                    items: [],
                    newcomers: [{ id: 8, name: 'Robo', isAi: true, href: '/member/8' }],
                    newGroups: [{ id: 3, name: 'Hikers', href: '/groups/3' }],
                }),
                day('2026-08-26', { items: [], newGroups: [{ id: 3, name: 'Hikers', href: '/groups/3' }] }),
            ]}
        />,
    );

    expect(within(cell(27)).getByRole('link', { name: 'Thu, August 27, 2026, Robo (AI)' })).toBeTruthy();
    expect(within(cell(26)).getByRole('link', { name: 'Wed, August 26, 2026, Hikers' })).toBeTruthy();
});

test('a day with nothing left is still a link, named by its date alone', () => {
    renderWithProviders(<MonthCalendar month={august} days={[day('2026-08-27', { items: [] })]} />);

    const link = within(cell(27)).getByRole('link', { name: 'Thu, August 27, 2026' });

    expect(link.textContent).toBe('27');
    expect(link.className).toContain('border');
});

test('an issue covering a stretch of days stands on the day it is dated', () => {
    renderWithProviders(<MonthCalendar month={august} days={[day('2026-08-27', {}, '2026-08-21')]} />);

    // Named as the block below names it, so the stretch is heard as one in both places.
    expect(within(cell(27)).getByRole('link', { name: 'August 21, 2026 to August 27, 2026, Morning walk' })).toBeTruthy();
    expect(within(cell(21)).queryByRole('link')).toBeNull();
    expect(screen.getAllByRole('link')).toHaveLength(1);
});
