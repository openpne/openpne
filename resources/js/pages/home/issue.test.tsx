import { cleanup, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import HomeIssue from './issue';
import type { GridImage } from '@/components/image-grid';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
import type { AuthUser, FeatureKey, NineTableItem } from '@/types';
import type { CommunityActivityEntry } from '../community/activity-row';
import type { HomeGroup } from '../unified/group-grid';
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

afterEach(cleanup);

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
    days: { from: '2026-08-27', to: '2026-08-27' },
    window: { from: '2026-08-27T06:00:00+09:00', to: '2026-08-28T06:00:00+09:00' },
    isCurrent: true,
    stories: [story()],
    ...overrides,
});

/** One page for the issue to be read off: the shared props, plus whatever the issue is today. */
function arrive(props: Record<string, unknown>) {
    inertia.page = {
        component: 'home/issue',
        url: '/',
        props: {
            name: 'Test SNS',
            auth: { user },
            enabledFeatures: allOn,
            look: 'standard',
            unread: null,
            locale: 'en',
            timezone: 'Asia/Tokyo',
            issue: null,
            prev: null,
            next: null,
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
    expect(hero?.getAttribute('sizes')).toBe('min(42rem, 100vw)');
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

    // A board entry's byline names the group it was posted in — as text, since the card is the link.
    expect(within(grid).getAllByText('Book club')).toHaveLength(2);
    expect(grid.querySelector('img[srcset]')?.getAttribute('sizes')).toBe('(min-width: 40rem) 21rem, 100vw');
});

test('a lone second takes the width rather than half of it', () => {
    const { container } = arrive({
        issue: issueOf({ stories: [story(), story({ id: 12, href: '/diary/12', headline: 'Second story' })] }),
    });

    expect(container.querySelector('.sm\\:grid-cols-2')).toBeNull();
    expect(screen.getByRole('heading', { level: 2, name: 'Second story' })).toBeTruthy();
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

const brief = (kind: 'topic' | 'event', id: number): CommunityActivityEntry => ({
    kind,
    id,
    name: `${kind} ${id}`,
    commentCount: 2,
    participantCount: kind === 'event' ? 4 : null,
    group,
    updatedAt: '2026-08-27T11:00:00+09:00',
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

const newcomer: NineTableItem = { id: 31, name: 'Aki', imageUrl: null, avatarColor: null, isAi: false, href: '/member/31' };
const newGroup: HomeGroup = { id: 41, name: 'Film club', imageUrl: null, href: '/groups/41' };
const upcoming: UpcomingEvent = { ...brief('event', 51), openDate: '2026-09-01' };

test('the whole issue is one page of articles, bands and the day around them', () => {
    arrive({
        issue: issueOf({
            stories: [story(), story({ id: 12, href: '/diary/12', headline: 'Second story' })],
            talkBursts: [burst(61), burst(62)],
            newcomers: [newcomer],
            newGroups: [newGroup],
            upcomingEvents: [upcoming],
        }),
    });

    expect(screen.getAllByRole('link', { name: 'Open talk' })).toHaveLength(2);
    expect(screen.getByText('New members')).toBeTruthy();
    expect(screen.getByText('New %communities%')).toBeTruthy();
    expect(screen.getByText('Upcoming events')).toBeTruthy();
});

test('a talk burst is the end of the conversation, printed', () => {
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

    expect(screen.getByRole('heading', { level: 2, name: '7 messages' })).toBeTruthy();

    // Six turns, read as a conversation: each one's words, under the name that said them.
    ['hello @rin', 'turn 2', 'turn 3', 'turn 4', 'nobody is here any more', 'turn 6'].forEach((line) =>
        expect(screen.getByText((_, el) => el?.textContent === line && el.tagName === 'P')).toBeTruthy(),
    );
    expect(screen.getAllByText('Rin')).toHaveLength(5);
    // The message stays and the person is gone.
    expect(screen.getByText('Withdrawn member')).toBeTruthy();
    // A mention in a message is the link it is everywhere else.
    expect(screen.getByRole('link', { name: '@rin' }).getAttribute('href')).toBe('/member/3');

    // What was posted rides with the turn it was posted in, at the size a strip draws.
    const glimpse = container.querySelector('img[srcset]');
    expect(glimpse?.getAttribute('srcset')).toBe('/f/9/w640 640w');
    expect(glimpse?.getAttribute('sizes')).toBe('6rem');

    expect(screen.getByRole('link', { name: 'Open talk' }).getAttribute('href')).toBe('/groups/61/talk');
});

test('a section the issue does not have is absent, not empty', () => {
    // Nothing counts to zero and says so: an issue with one story has no talk, no newcomers and no
    // events, and the page says nothing at all about them.
    arrive({ issue: issueOf() });

    expect(screen.queryByText('New members')).toBeNull();
    expect(screen.queryByText('New %communities%')).toBeNull();
    expect(screen.queryByText('Upcoming events')).toBeNull();
    expect(screen.queryByText('Open talk')).toBeNull();
    expect(screen.queryByText('0')).toBeNull();
});

test('an issue whose stories have all gone is still the day around them', () => {
    // Every story was taken down after publication, so the payload carries no `stories` at all.
    const { container } = arrive({
        issue: issueOf({ stories: undefined, talkBursts: [burst(61)], newcomers: [newcomer] }),
    });

    expect(screen.getByRole('heading', { level: 2, name: '7 messages' })).toBeTruthy();
    expect(screen.getByText('New members')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Aki' }).getAttribute('href')).toBe('/member/31');

    // Nothing stands where the stories were: no article, no card, and no count of what is missing.
    expect(container.querySelector('.rich-body')).toBeNull();
    expect(screen.queryByText('Morning walk')).toBeNull();
    expect(screen.queryByText('0')).toBeNull();
});

test('the foot of the page says which stretch it was drawn from, and whether it is stale', () => {
    arrive({ issue: issueOf({ isCurrent: false }) });
    expect(screen.getByText('Nothing new today yet — the next post starts a new day here.')).toBeTruthy();

    cleanup();

    // An archived day is not stale — there is a day after it, so "nothing new yet" would be false.
    arrive({ issue: issueOf({ isCurrent: false }), next: { date: '2026-08-28', number: 13, href: '/home/2026/08/28' } });
    expect(screen.queryByText('Nothing new today yet — the next post starts a new day here.')).toBeNull();

    cleanup();

    arrive({ issue: issueOf() });
    expect(screen.queryByText('Nothing new today yet — the next post starts a new day here.')).toBeNull();
    // A day here runs 06:00 to 06:00, so the page states the two instants the masthead's day means.
    expect(screen.getByText('Posts from August 27, 2026 at 06:00 to August 28, 2026 at 06:00')).toBeTruthy();
    expect(screen.getByText('Posts deleted or made private since are not shown here.')).toBeTruthy();
});

test('a site with nothing published yet offers the way to fill it', () => {
    arrive({ issue: null });

    expect(screen.getByText('Welcome, Viewer.')).toBeTruthy();
    expect(screen.getByText('The first post will appear here the next morning.')).toBeTruthy();
    // Nothing has been put together, so there is no stretch to state and nothing to page through.
    expect(screen.queryByText(/^Posts from /)).toBeNull();
    expect(screen.queryByText('Past happenings')).toBeNull();
});

test('the pager offers only the directions there is an issue in', () => {
    arrive({
        issue: issueOf(),
        prev: { date: '2026-08-26', number: 11, href: '/home/2026/08/26' },
        next: { date: '2026-08-28', number: 13, href: '/home/2026/08/28' },
    });

    expect(screen.getByText('Earlier day')).toBeTruthy();
    expect(screen.getByText('Later day')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Past happenings' }).getAttribute('href')).toBe('/home/issues');

    cleanup();

    arrive({ issue: issueOf() });

    expect(screen.queryByText('Earlier day')).toBeNull();
    expect(screen.queryByText('Later day')).toBeNull();
});
