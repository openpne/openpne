import { cleanup, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import HomeIssues from './issues';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
import type { DaySummary, DayStory, DayTalk, MonthRef } from './types';

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
    group: { id: 3, name: 'Hikers', imageUrl: null },
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
    items: [],
    more: 0,
    newcomers: [],
    newGroups: [],
    ...rest,
});

function arrive(props: Record<string, unknown>) {
    inertia.page = {
        component: 'home/issues',
        url: '/home/2026/08',
        props: { locale: 'en', timezone: 'Asia/Tokyo', month: month(2026, 8), prev: null, next: null, days: [], months: [], ...props },
    };

    return renderWithProviders(<HomeIssues />);
}

/** A day of the month, not an item of the list inside one. */
function block(index: number): HTMLElement {
    const found = screen.getAllByRole('list')[0]?.children[index];

    if (!(found instanceof HTMLElement)) {
        throw new Error(`no day ${index}`);
    }

    return found;
}

const links = (within_: HTMLElement): (string | null)[][] =>
    within(within_)
        .getAllByRole('link')
        .map((link) => [link.textContent, link.getAttribute('href')]);

test('a day is headed by its date, which opens the day', () => {
    arrive({ days: [day('2026-08-27', { items: [story(1, 'Morning walk')] }), day('2026-08-26', { items: [story(2, 'Evening run')] })] });

    const heading = within(block(0)).getByRole('heading', { level: 3, name: 'Thu, August 27, 2026' });

    expect(within(heading).getByRole('link').getAttribute('href')).toBe('/home/2026/08/27');
    expect(within(block(1)).getByRole('heading', { level: 3, name: 'Wed, August 26, 2026' })).toBeTruthy();
});

test('each item opens itself, in the order the day gives them', () => {
    arrive({
        days: [day('2026-08-27', { items: [story(1, 'Morning walk'), talk(), story(2, 'Evening run')] })],
    });

    expect(links(block(0))).toEqual([
        ['Thu, August 27, 2026', '/home/2026/08/27'],
        ['Morning walk', '/diary/1'],
        ['Hanako: Photos are up', '/groups/3/talk?m=40'],
        ['Evening run', '/diary/2'],
    ]);
});

test('a story says how much was said under it, and nothing when nothing was', () => {
    arrive({
        days: [day('2026-08-27', { items: [story(1, 'Morning walk', { responses: 8 }), story(2, 'Evening run'), story(3, 'Noon nap', { responses: 1 })] })],
    });

    const items = within(block(0)).getAllByRole('listitem');

    expect(items.map((item) => item.textContent)).toEqual(['Morning walk8 responses', 'Evening run', 'Noon nap1 response']);
});

test('a room is drawn by what was last said in it, under its name and its count', () => {
    arrive({ days: [day('2026-08-27', { items: [talk()] })] });

    const [item] = within(block(0)).getAllByRole('listitem');

    expect(within(item as HTMLElement).getByRole('link', { name: 'Hanako: Photos are up' })).toBeTruthy();
    expect(within(item as HTMLElement).getByText('Hikers')).toBeTruthy();
    expect(within(item as HTMLElement).getByText('23 messages')).toBeTruthy();
    // The group's image is a mark beside its name and never the item's picture.
    expect(item?.querySelector('img')).toBeNull();
});

test('a speaker is named as what they are', () => {
    arrive({
        days: [
            day('2026-08-27', {
                items: [
                    talk({ href: '/groups/3/talk?m=1', speaker: { name: 'Robo', isAi: true } }),
                    talk({ href: '/groups/3/talk?m=2', speaker: null }),
                ],
            }),
        ],
    });

    expect(screen.getByRole('link', { name: 'Robo (AI): Photos are up' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Withdrawn member: Photos are up' })).toBeTruthy();
});

test('a message with nothing to show of it is called by its room', () => {
    arrive({ days: [day('2026-08-27', { items: [talk({ line: '', count: 1 })] })] });

    const [item] = within(block(0)).getAllByRole('listitem');

    expect(within(item as HTMLElement).getByRole('link', { name: 'Hikers' }).getAttribute('href')).toBe('/groups/3/talk?m=40');
    // Said once: the name is the link, so the line under it is the count alone.
    expect(within(item as HTMLElement).getAllByText('Hikers')).toHaveLength(1);
    expect(within(item as HTMLElement).getByText('1 message')).toBeTruthy();
});

test('a picture is drawn for the item that has one', () => {
    arrive({
        days: [day('2026-08-27', { items: [story(1, 'Morning walk', { image: picture }), talk({ image: picture }), story(2, 'Evening run')] })],
    });

    const items = within(block(0)).getAllByRole('listitem');

    expect(items.map((item) => item.querySelector('img')?.getAttribute('src') ?? null)).toEqual([
        '/cache/img/9-320.png',
        '/cache/img/9-320.png',
        null,
    ]);
});

test('the names of a day lead to who and what they name', () => {
    arrive({
        days: [
            day('2026-08-27', {
                newcomers: [
                    { id: 7, name: 'Hanako', isAi: false, href: '/member/7' },
                    { id: 8, name: 'Robo', isAi: true, href: '/member/8' },
                ],
                newGroups: [{ id: 3, name: 'Hikers', href: '/groups/3' }],
            }),
        ],
    });

    expect(within(block(0)).getByText('New members')).toBeTruthy();
    expect(within(block(0)).getByText('New %communities%')).toBeTruthy();
    expect(links(block(0)).slice(1)).toEqual([
        ['Hanako', '/member/7'],
        ['Robo (AI)', '/member/8'],
        ['Hikers', '/groups/3'],
    ]);
});

test('what is not shown is counted, and opens the day', () => {
    arrive({
        days: [
            day('2026-08-27', { items: [story(1, 'Morning walk')], more: 5 }),
            day('2026-08-26', { items: [story(2, 'Evening run')], more: 1 }),
            day('2026-08-25', { items: [story(3, 'Noon nap')] }),
        ],
    });

    expect(within(block(0)).getByRole('link', { name: '5 more' }).getAttribute('href')).toBe('/home/2026/08/27');
    expect(within(block(1)).getByRole('link', { name: '1 more' }).getAttribute('href')).toBe('/home/2026/08/26');
    expect(within(block(2)).queryByText(/more/)).toBeNull();
});

test('a stretch of days is named as one', () => {
    arrive({ days: [day('2026-08-27', { items: [story(1, 'Morning walk')] }, '2026-08-21')] });

    expect(within(block(0)).getByRole('heading', { level: 3, name: 'August 21, 2026 to August 27, 2026' })).toBeTruthy();
});

test('a day with nothing left is its date alone', () => {
    arrive({ days: [day('2026-08-27')] });

    expect(links(block(0))).toEqual([['Thu, August 27, 2026', '/home/2026/08/27']]);
    expect(block(0).querySelector('p, ul, img')).toBeNull();
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
        days: [day('2026-08-27', { items: [story(1, 'Morning walk')] })],
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
