import { act, cleanup, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import HomeIssue from './issue';
import type { GridImage } from '@/components/image-grid';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
import type { AuthUser, FeatureKey, PersonTile } from '@/types';
import type { CommunityActivityEntry } from '../community/activity-row';
import type { HomeGroup } from '@/components/sections/group-grid';
import type { Issue, IssueStory, TalkBurst, TalkExcerptMessage, UpcomingEvent } from './types';

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
    router: { visit: () => {} },
}));

beforeEach(() => {
    // Inside the issue's year, so the dates below print without one whenever the suite runs.
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-08-28T03:00:00Z'));
});

afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

const allOn = Object.fromEntries(
    ['diary', 'directMessage', 'timeline', 'group', 'groupTopic', 'groupEvent', 'groupTalk', 'friend', 'mcp'].map((unit) => [unit, true]),
) as Record<FeatureKey, boolean>;

const user: AuthUser = { id: 1, name: 'Viewer', email: 'viewer@example.com', imageUrl: null, avatarColor: null, isAi: false };

const author = { id: 3, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false };
const group = { id: 5, name: 'Book club', imageUrl: null };

const picture = (id: number): GridImage => ({
    id,
    url: `/f/${id}`,
    thumbnailUrl: `/f/${id}/thumb`,
    fitSources: [{ url: `/f/${id}/w640`, box: 640 }],
    cropSources: { tall: [{ url: `/f/${id}/tall`, width: 300 }], wide: [{ url: `/f/${id}/wide`, width: 300 }] },
    width: 800,
    height: 600,
    animatedSources: [],
});

const story = (overrides: Partial<IssueStory> = {}): IssueStory => ({
    kind: 'diary',
    id: 11,
    href: '/diary/11',
    headline: 'Morning walk',
    dek: 'Down to the river and back.',
    author,
    group: null,
    createdAt: '2026-08-27T09:00:00+09:00',
    commentCount: 0,
    image: picture(1),
    ...overrides,
});

/** The links a block offers, which for a whole-block link is exactly one: the headline's. */
const linksIn = (block: Element): string[] =>
    Array.from(block.querySelectorAll('a')).map((link) => `${link.textContent} -> ${link.getAttribute('href')}`);

const issueOf = (overrides: Partial<Issue> = {}): Issue => ({
    date: '2026-08-27',
    number: 12,
    href: '/home/2026/08/27',
    monthHref: '/home/2026/08',
    bornDays: { from: '2026-08-27', to: '2026-08-27' },
    window: { from: '2026-08-27T06:00:00+09:00', to: '2026-08-28T06:00:00+09:00' },
    isCurrent: true,
    stories: [story()],
    ...overrides,
});

/** One page for the issue to be read off: the shared props, plus whatever the issue is today. */
function arrive(props: Record<string, unknown>, component = 'home/issue') {
    inertia.page = {
        component,
        url: '/',
        props: {
            name: 'Test SNS',
            auth: { user },
            enabledFeatures: allOn,
            unread: null,
            locale: 'en',
            timezone: 'Asia/Tokyo',
            issue: null,
            prev: null,
            next: null,
            notice: null,
            ...props,
        },
    };

    return renderWithProviders(<HomeIssue />);
}

test('the lead is one block: a hero over the headline, and nothing else in it to click', () => {
    const { container } = arrive({ issue: issueOf() });

    const card = container.querySelector('.rounded-card') as HTMLElement;
    const hero = card.querySelector('img[srcset]');

    expect(hero?.getAttribute('srcset')).toBe('/f/1/w640 640w');
    expect(hero?.getAttribute('sizes')).toBe('min(45rem, 100vw)');
    expect(hero?.getAttribute('alt')).toBe('');

    const headline = screen.getByRole('heading', { level: 2, name: 'Morning walk' });
    expect(headline.className).toContain('text-2xl');

    // One link in the whole card, named by the headline and stretched over the block that positions it.
    expect(linksIn(card)).toEqual(['Morning walk -> /diary/11']);
    expect(within(headline).getByRole('link', { name: 'Morning walk' }).className).toContain('after:absolute');
    expect(card.className).toContain('relative');

    // What the story says, not the story: no body, no card under it, and nothing offering to continue.
    expect(screen.getByText('Down to the river and back.')).toBeTruthy();
    expect(container.querySelector('.rich-body')).toBeNull();
    expect(container.querySelector('a[href^="http"]')).toBeNull();
    expect(screen.queryByText('Continue reading')).toBeNull();
});

test('a lead with no picture keeps its rank and reads a line longer', () => {
    const { container } = arrive({ issue: issueOf({ stories: [story({ image: null })] }) });

    // No hero-shaped gap where there is no photograph: the lead's weight is its placement and size.
    expect(container.querySelector('img[srcset]')).toBeNull();
    expect(screen.getByRole('heading', { level: 2, name: 'Morning walk' }).className).toContain('text-2xl');
    expect(screen.getByText('Down to the river and back.').className).toContain('line-clamp-4');
});

test('the two after the lead are cards side by side, a rank down', () => {
    const { container } = arrive({
        issue: issueOf({
            stories: [
                story(),
                story({ kind: 'topic', id: 12, href: '/topics/12', headline: 'Second story', group }),
                story({ kind: 'event', id: 13, href: '/events/13', headline: 'Third story', group }),
            ],
        }),
    });

    const headings = screen.getAllByRole('heading', { level: 2 });
    expect(headings.map((heading) => heading.textContent)).toEqual(['Morning walk', 'Second story', 'Third story']);
    expect(headings.map((heading) => heading.className.includes('text-2xl'))).toEqual([true, false, false]);
    expect(headings.map((heading) => heading.className.includes('text-lg'))).toEqual([false, true, true]);

    const grid = container.querySelector('.sm\\:grid-cols-2') as HTMLElement;
    expect(grid.children).toHaveLength(2);
    // Each card is one link, and it is its headline's.
    expect(Array.from(grid.children).map((card) => linksIn(card))).toEqual([
        ['Second story -> /topics/12'],
        ['Third story -> /events/13'],
    ]);

    // A board entry's group is named once, in its mark and not again in the byline.
    expect(within(grid).getAllByText('Book club')).toHaveLength(2);
    expect(grid.querySelector('img[srcset]')?.getAttribute('sizes')).toBe('(min-width: 40rem) 22.5rem, 100vw');
});

test('a lone second takes the width rather than half of it', () => {
    const { container } = arrive({
        issue: issueOf({ stories: [story(), story({ id: 12, href: '/diary/12', headline: 'Second story' })] }),
    });

    expect(container.querySelector('.sm\\:grid-cols-2')).toBeNull();
    expect(screen.getByRole('heading', { level: 2, name: 'Second story' })).toBeTruthy();
    const pictures = Array.from(container.querySelectorAll('img[srcset]'));
    expect(pictures.map((picture) => picture.getAttribute('sizes'))).toEqual(['min(45rem, 100vw)', 'min(45rem, 100vw)']);
});

test('rank four and below are rows, with the picture beside the words where there is one', () => {
    const { container } = arrive({
        issue: issueOf({
            stories: [
                story(),
                story({ id: 12, href: '/diary/12', headline: 'Second' }),
                story({ id: 13, href: '/diary/13', headline: 'Third' }),
                story({ id: 14, href: '/diary/14', headline: 'Fourth', dek: 'What the fourth says.' }),
                story({ kind: 'timeline', id: 15, href: '/timeline/15', headline: 'Fifth', image: null, commentCount: 2 }),
            ],
        }),
    });

    const [withPicture, withNone] = Array.from(container.querySelectorAll('li'));
    const rows = [withPicture, withNone];
    expect(container.querySelectorAll('li')).toHaveLength(2);

    // A row's headline is its content line, not a heading rank: only the lead and the two cards are.
    expect(screen.getAllByRole('heading', { level: 2 }).map((heading) => heading.textContent)).toEqual([
        'Morning walk',
        'Second',
        'Third',
    ]);

    expect(withPicture?.querySelector('img[srcset]')?.getAttribute('sizes')).toBe('6rem');
    expect(withNone?.querySelector('img')).toBeNull();

    // One link per row, so anywhere in it opens the story.
    expect(rows.map((row) => linksIn(row as Element))).toEqual([['Fourth -> /diary/14'], ['Fifth -> /timeline/15']]);
    expect(screen.getByText('What the fourth says.').className).toContain('line-clamp-1');
});

test('every card opens on its kind in words, a board entry with its group, and none of it is a link', () => {
    arrive({
        issue: issueOf({
            stories: [
                story(),
                story({ kind: 'topic', id: 12, href: '/topics/12', headline: 'Second story', group }),
                story({ kind: 'event', id: 13, href: '/events/13', headline: 'Third story', group }),
                story({ kind: 'timeline', id: 15, href: '/timeline/15', headline: 'Fourth', image: null }),
            ],
            talkBursts: [burst(61)],
        }),
    });

    const marks = ['%Diary%', '%Topic%', 'Event', '%Activity%', 'Talk'].map((word) => screen.getByText(word).parentElement as HTMLElement);
    expect(marks.map((mark) => mark.textContent)).toEqual(['%Diary%', '%Topic%·Book club', 'Event·Book club', '%Activity%', 'Talk']);

    const headlines = ['Morning walk', 'Second story', 'Third story', 'Fourth', 'Room 61'].map((name) => screen.getByRole('link', { name }));
    marks.forEach((mark, i) => {
        expect(mark.closest('a')).toBeNull();
        expect(mark.querySelector('svg')?.getAttribute('aria-hidden')).toBe('true');
        // Over its own headline, inside the block that headline's link covers.
        const block = mark.closest('.rounded-card, li') as HTMLElement;
        expect(block.contains(headlines[i] as HTMLElement)).toBe(true);
        expect(mark.compareDocumentPosition(headlines[i] as HTMLElement) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });
});

const brief = (kind: 'topic' | 'event', id: number): CommunityActivityEntry => ({
    kind,
    id,
    name: `${kind} ${id}`,
    commentCount: 2,
    participantCount: kind === 'event' ? 4 : null,
    openDate: kind === 'event' ? '2030-04-05' : null,
    group,
    bumpedAt: '2026-08-27T11:00:00+09:00',
});

const said = (id: number, overrides: Partial<TalkExcerptMessage> = {}): TalkExcerptMessage => ({
    id,
    author,
    body: `turn ${id}`,
    mentions: [],
    createdAt: '2026-08-27T07:00:00+09:00',
    images: [],
    ...overrides,
});

const burst = (id: number): TalkBurst => ({
    group: { id, name: `Room ${id}`, imageUrl: null },
    count: 7,
    messages: [1, 2, 3, 4, 5].map((n) => said(id * 100 + n)),
    href: `/groups/${id}/talk`,
});

const newcomer: PersonTile = { id: 31, name: 'Aki', imageUrl: null, avatarColor: null, isAi: false, href: '/member/31' };
const newGroup: HomeGroup = { id: 41, name: 'Film club', imageUrl: null, href: '/groups/41' };
const upcoming: UpcomingEvent = { ...brief('event', 51), openDate: '2026-09-01' };

test('the sections are named in the order the page reads, a talk room by its own name', () => {
    arrive({
        issue: issueOf({
            stories: [story(), story({ id: 12, href: '/diary/12', headline: 'Second story' })],
            talkBursts: [burst(61), burst(62)],
            newcomers: [newcomer],
            newGroups: [newGroup],
            upcomingEvents: [upcoming],
        }),
    });

    expect(screen.getAllByRole('heading', { level: 2 }).map((heading) => heading.textContent)).toEqual([
        'Morning walk',
        'Second story',
        'Room 61',
        'Room 62',
        'Upcoming events',
        'New members',
        'New %communities%',
    ]);
});

test('a talk burst is a card that is one link into its room, over the end of the conversation', () => {
    const { container } = arrive({
        issue: issueOf({
            stories: undefined,
            talkBursts: [
                {
                    ...burst(61),
                    messages: [
                        said(1, { body: 'hello @rin', mentions: [{ offset: 6, length: 4, memberId: 3 }] }),
                        said(2),
                        said(3),
                        said(4),
                        said(5, { author: null, body: 'nobody is here any more' }),
                        said(6, { images: [picture(9)] }),
                    ],
                },
            ],
        }),
    });

    const room = screen.getByRole('heading', { level: 2, name: 'Room 61' });
    // A mention is text: the card around it is the link.
    expect(linksIn(room.parentElement?.parentElement as HTMLElement)).toEqual(['Room 61 -> /groups/61/talk']);
    expect(screen.getByText('7 messages')).toBeTruthy();

    // Six turns, read as a conversation: each one's words, under the name that said them.
    ['hello @rin', 'turn 2', 'turn 3', 'turn 4', 'nobody is here any more', 'turn 6'].forEach((line) =>
        expect(screen.getByText((_, el) => el?.textContent === line && el.tagName === 'P')).toBeTruthy(),
    );
    expect(screen.getAllByText('Rin')).toHaveLength(5);
    // The message stays and the person is gone.
    expect(screen.getByText('%Withdrawn_member%')).toBeTruthy();

    // What was posted rides with the turn it was posted in, at the size a strip draws.
    const glimpse = container.querySelector('img[srcset]');
    expect(glimpse?.getAttribute('srcset')).toBe('/f/9/w640 640w');
    expect(glimpse?.getAttribute('sizes')).toBe('6rem');

});

test("an AI speaker's face lets a tap through to the card's link", () => {
    const { container } = arrive({
        issue: issueOf({ stories: undefined, talkBursts: [{ ...burst(61), messages: [said(1, { author: { ...author, isAi: true } })] }] }),
    });

    const face = container.querySelector('li span.relative.inline-flex');
    expect(face).not.toBeNull();
    expect(face?.classList.contains('pointer-events-none')).toBe(true);
});

test('an upcoming event is a row: its day, its title, and where it is held', () => {
    arrive({ issue: issueOf({ upcomingEvents: [upcoming] }) });

    const link = screen.getByRole('link', { name: 'event 51' });
    expect(link.getAttribute('href')).toBe('/events/51');
    const row = link.closest('li') as HTMLElement;
    expect(row.querySelector('time')?.getAttribute('datetime')).toBe('2026-09-01');
    expect(row.querySelector('time')?.textContent).toBe('Tue, Sep 1');
    expect(within(row).getByText('Book club')).toBeTruthy();
    expect(within(row).getByText('4 participants')).toBeTruthy();
});

test('newcomers and new groups are names to follow, an AI account named as one', () => {
    arrive({
        issue: issueOf({
            newcomers: [newcomer, { ...newcomer, id: 32, name: 'Bot', isAi: true, href: '/member/32' }],
            newGroups: [newGroup],
        }),
    });

    expect(screen.getByRole('link', { name: 'Aki' }).getAttribute('href')).toBe('/member/31');
    // The corner mark on the face is aria-hidden, so the name carries the fact.
    expect(screen.getByRole('link', { name: 'Bot (AI)' }).getAttribute('href')).toBe('/member/32');
    expect(screen.getByRole('link', { name: 'Film club' }).getAttribute('href')).toBe('/groups/41');
});

test('a section the issue does not have is absent, not empty', () => {
    // Nothing counts to zero and says so: an issue with one story has no talk, no newcomers and no
    // events, and the page says nothing at all about them.
    const { container } = arrive({ issue: issueOf() });

    expect(screen.queryByText('New members')).toBeNull();
    expect(screen.queryByText('New %communities%')).toBeNull();
    expect(screen.queryByText('Upcoming events')).toBeNull();
    expect(container.querySelector('a[href*="/talk"]')).toBeNull();
    expect(screen.queryByText('0')).toBeNull();
});

test('an issue whose stories have all gone is still the day around them', () => {
    // Every story was taken down after publication, so the payload carries no `stories` at all.
    const { container } = arrive({
        issue: issueOf({ stories: undefined, talkBursts: [burst(61)], newcomers: [newcomer] }),
    });

    expect(screen.getByRole('heading', { level: 2, name: 'Room 61' })).toBeTruthy();
    expect(screen.getByText('New members')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Aki' }).getAttribute('href')).toBe('/member/31');

    // Nothing stands where the stories were: no article, and no count of what is missing.
    expect(container.querySelector('.rich-body')).toBeNull();
    expect(screen.queryByText('Morning walk')).toBeNull();
    expect(screen.queryByText('0')).toBeNull();
});

test('the band names the days what is shown was born on, not the date in the URL', () => {
    arrive({ issue: issueOf({ bornDays: { from: '2026-08-25', to: '2026-08-25' } }) });

    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('What happened on Tue, Aug 25');
});

test("a past day's band is its date under the section's h1, opening the month's issues; the front page's is the h1 itself", () => {
    const monthDays = [
        { date: '2026-08-27', number: 12, href: '/home/2026/08/27' },
        { date: '2026-08-21', number: 8, href: '/home/2026/08/21' },
    ];
    arrive({ issue: issueOf(), monthDays }, 'home/archive');

    expect(screen.queryByRole('heading', { level: 1 })).toBeNull();
    act(() => screen.getByRole('button', { name: 'Thu, Aug 27 Calendar' }).click());
    expect(screen.getByRole('link', { name: 'Fri, Aug 21' }).getAttribute('href')).toBe('/home/2026/08/21');
    expect(screen.getByRole('link', { name: 'Aug at a glance' }).getAttribute('href')).toBe('/home/2026/08');

    cleanup();

    arrive({ issue: issueOf() });

    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('What happened yesterday');
    expect(screen.queryByRole('button', { name: /Calendar/ })).toBeNull();
});

test('the foot steps to the days either side and into the calendar', () => {
    arrive({
        issue: issueOf(),
        prev: { date: '2026-08-26', number: 11, href: '/home/2026/08/26' },
        next: { date: '2026-08-28', number: 13, href: '/home/2026/08/28' },
    });

    const foot = screen.getByRole('navigation', { name: 'Day navigation' });
    expect(within(foot).getByRole('link', { name: 'View in calendar' }).getAttribute('href')).toBe('/home/2026/08');
    expect(within(foot).getByRole('link', { name: /Earlier day/ }).getAttribute('href')).toBe('/home/2026/08/26');
    expect(within(foot).getByRole('link', { name: /Later day/ }).textContent).toContain('Fri, Aug 28');

    cleanup();

    arrive({ issue: issueOf() });

    const alone = screen.getByRole('navigation', { name: 'Day navigation' });
    expect(within(alone).getAllByRole('link').map((link) => link.textContent)).toEqual(['View in calendar']);
});

test('the colophon states the stretch the issue was drawn from and what it cannot show, nothing more', () => {
    // The newest issue with nothing after it once added a line calling itself stale; the band's
    // "next update" took that over.
    const { container } = arrive({ issue: issueOf({ isCurrent: false }) });

    // Not by role: the frame puts the page in <main>, where a footer is no contentinfo landmark.
    const colophon = container.querySelector('footer') as HTMLElement;
    // A day here runs 06:00 to 06:00, so the page states the two instants the band's day means.
    expect(Array.from(colophon.querySelectorAll('p')).map((line) => line.textContent)).toEqual([
        'Posts from August 27, 2026 at 06:00 to August 28, 2026 at 06:00',
        'Posts deleted or made private since are not shown here.',
    ]);
});

test('a site with nothing published yet offers the way to fill it', () => {
    arrive({ issue: null });

    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('What happened');
    expect(screen.getByText('Next update at 06:00')).toBeTruthy();
    expect(screen.getByText('Welcome, Viewer.')).toBeTruthy();
    expect(screen.getByText('The first post will appear here the next morning.')).toBeTruthy();
    // Nothing has been put together, so there is no stretch to state and nothing to page through.
    expect(screen.queryByText(/^Posts from /)).toBeNull();
    expect(screen.queryByRole('navigation', { name: 'Day navigation' })).toBeNull();
});

test('the site notice opens the page with an issue and without one', () => {
    const notice = { body: 'Hello', bodyHtml: '<p>Hello</p>' };

    arrive({ issue: issueOf(), notice });
    expect(screen.getByRole('region', { name: 'Notice from the site' }).textContent).toContain('Hello');
    cleanup();

    arrive({ issue: null, notice });
    expect(screen.getByRole('region', { name: 'Notice from the site' }).textContent).toContain('Hello');
});
