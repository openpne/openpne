import { cleanup, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import HomeIssue from './issue';
import type { GridImage } from '@/components/image-grid';
import type { LinkCardData } from '@/components/link-card';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
import type { AuthUser, FeatureKey, NineTableItem } from '@/types';
import type { CommunityActivityEntry } from '../community/activity-row';
import type { DiaryDetail, DiarySummary } from '../diary/types';
import type { TimelinePostEntry } from '../timeline/types';
import type { HomeGroup } from '../unified/group-grid';
import type { Issue, IssueStory, TalkBurst, UpcomingEvent } from './types';

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

const card: LinkCardData = {
    url: 'https://www.example.com/article',
    title: 'A title from the page',
    description: 'What the page says it is about.',
    siteName: 'Example',
    domain: 'example.com',
    layout: 'compact',
    imageUrl: null,
    imageWidth: null,
    imageHeight: null,
    fitSources: [],
};

const diary = (overrides: Partial<DiaryDetail> = {}): DiaryDetail => ({
    id: 11,
    title: 'Morning walk',
    excerpt: 'Down to the river and back.',
    visibility: 'open',
    commentCount: 0,
    hasImages: true,
    thumbnails: [],
    author,
    createdAt: '2026-08-27T09:00:00+09:00',
    body: 'Down to the river and back.',
    format: 'op3',
    bodyHtml: '<p>Down to the <strong>river</strong> and back.</p>',
    linkCard: card,
    images: [picture(1), picture(2)],
    ...overrides,
});

const post = (overrides: Partial<TimelinePostEntry> = {}): TimelinePostEntry => ({
    id: 21,
    body: 'A one-line post',
    visibility: 'open',
    hasImages: false,
    replyCount: 0,
    images: [],
    mentions: [],
    tags: [],
    linkCard: null,
    author,
    createdAt: '2026-08-27T10:00:00+09:00',
    ...overrides,
});

const diaryStory = (overrides: Partial<DiaryDetail> = {}): IssueStory => ({ kind: 'diary', item: diary(overrides) });

const issueOf = (overrides: Partial<Issue> = {}): Issue => ({
    date: '2026-08-27',
    number: 12,
    href: '/home/2026/08/27',
    publishedAt: '2026-08-27T06:00:00+09:00',
    isCurrent: true,
    topStory: diaryStory(),
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

const documentOrder = (container: HTMLElement) => Array.from(container.querySelectorAll('*'));

test('a one-story issue is printed whole: rich body, its card, and the pictures above it', () => {
    const { container } = arrive({ issue: issueOf() });

    // The server-rendered decoration, not the plain fallback.
    const body = container.querySelector('.rich-body');
    expect(body?.textContent).toBe('Down to the river and back.');
    expect(body?.querySelector('strong')).toBeTruthy();

    // The one place in an issue a body is printed is the one place its card is drawn.
    expect(container.querySelector('a[href="https://www.example.com/article"]')).toBeTruthy();

    const headline = screen.getByRole('heading', { level: 2, name: 'Morning walk' });
    expect(headline.className).toContain('text-2xl');

    // The pictures lead the article; the words follow them.
    const order = documentOrder(container);
    const hero = order.findIndex((el) => el.getAttribute('aria-label') === 'Image 1');
    const words = order.findIndex((el) => el.classList.contains('rich-body'));
    expect(hero).toBeGreaterThanOrEqual(0);
    expect(hero).toBeLessThan(words);
});

test('a post headlined by its first line keeps the mention in it a link', () => {
    const story: IssueStory = {
        kind: 'timeline',
        item: {
            ...post({
                body: '@rin what a morning\nWalked down to the river and back.',
                mentions: [{ offset: 0, length: 4, memberId: 3 }],
            }),
            excerpt: 'Walked down to the river and back.',
        },
    };

    arrive({ issue: issueOf({ topStory: story }) });

    const headline = screen.getByRole('heading', { level: 2 });
    expect(headline.textContent).toBe('@rin what a morning');
    expect(within(headline).getByRole('link', { name: '@rin' }).getAttribute('href')).toBe('/member/3');

    // The rest of the post is the body; only the first line was spent on the headline.
    expect(screen.getByText('Walked down to the river and back.')).toBeTruthy();
});

test('the lead takes the width and the rest run two abreast, restating no link', () => {
    const { container } = arrive({
        issue: issueOf({
            features: [
                diaryStory({ id: 12, title: 'Second story' }),
                diaryStory({ id: 13, title: 'Third story' }),
            ],
        }),
    });

    // The lead is a card like the others, and it stands alone above them: past one story, ranking
    // the rest would be a claim the day's traffic does not support.
    const headings = screen.getAllByRole('heading', { level: 2 });
    expect(headings.map((heading) => heading.textContent)).toEqual(['Morning walk', 'Second story', 'Third story']);
    headings.forEach((heading) => expect(heading.className).toContain('text-lg'));

    // Two columns and never three — a third leaves a card too narrow to read a headline across.
    const grid = container.querySelector('div.grid');
    expect(grid?.className).toContain('sm:grid-cols-2');
    expect(grid?.className).not.toContain('grid-cols-3');
    expect(grid?.children.length).toBe(2);

    // A preview does not restate a link out of a body it is not showing.
    expect(container.querySelector('a[href="https://www.example.com/article"]')).toBeNull();
});

test('a lone follow-up takes the width too rather than sitting in half a row', () => {
    const { container } = arrive({ issue: issueOf({ features: [diaryStory({ id: 12, title: 'Second story' })] }) });

    expect(container.querySelector('div.grid')?.className).not.toContain('grid-cols-2');
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

const summary = (id: number): DiarySummary => ({
    id,
    title: `Diary ${id}`,
    excerpt: '',
    visibility: 'open',
    commentCount: 0,
    hasImages: false,
    thumbnails: [],
    author,
    createdAt: '2026-08-27T08:00:00+09:00',
});

const burst = (id: number): TalkBurst => ({
    group: { id, name: `Room ${id}`, imageUrl: null },
    count: 7,
    since: '2026-08-27T07:00:00+09:00',
    participants: [author],
    thumbnails: [picture(9)],
    href: `/groups/${id}/talk`,
});

const newcomer: NineTableItem = { id: 31, name: 'Aki', imageUrl: null, avatarColor: null, isAi: false, href: '/member/31' };
const newGroup: HomeGroup = { id: 41, name: 'Film club', imageUrl: null, href: '/groups/41' };
const upcoming: UpcomingEvent = { ...brief('event', 51), openDate: '2026-09-01' };

test('past three stories the lead keeps its card and the rest become rows, under the day around them', () => {
    const { container } = arrive({
        issue: issueOf({
            briefs: [
                { kind: 'diary', item: summary(101) },
                { kind: 'timeline', item: post({ id: 102 }) },
                { kind: 'topic', item: brief('topic', 103) },
                { kind: 'event', item: brief('event', 104) },
                { kind: 'diary', item: summary(105) },
            ],
            talkBursts: [burst(61), burst(62)],
            newcomers: [newcomer],
            newGroups: [newGroup],
            upcomingEvents: [upcoming],
        }),
    });

    expect(screen.getByRole('heading', { level: 2, name: 'Morning walk' }).className).toContain('text-lg');

    const lists = container.querySelectorAll('ul.divide-y');
    expect(lists[0]?.children.length).toBe(5);

    expect(screen.getAllByRole('link', { name: 'Open talk' })).toHaveLength(2);
    expect(screen.getByText('New members')).toBeTruthy();
    expect(screen.getByText('New %communities%')).toBeTruthy();
    expect(screen.getByText('Upcoming events')).toBeTruthy();
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
    // Every story was taken down after publication, so the payload carries no `topStory` at all. The
    // talk and the faces are still an issue; the story band is simply not one of the things it has.
    const { container } = arrive({
        issue: issueOf({ topStory: undefined, talkBursts: [burst(61)], newcomers: [newcomer] }),
    });

    expect(screen.getByRole('heading', { level: 2, name: '7 messages' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Open talk' }).getAttribute('href')).toBe('/groups/61/talk');

    // A glimpse of the run is a picture, not a marker beside the faces: drawn from the fit ladder,
    // at the resolution the cell it lands in asks for.
    const glimpse = container.querySelector('img[srcset]');
    expect(glimpse?.getAttribute('srcset')).toBe('/f/9/w640 640w');
    expect(glimpse?.getAttribute('sizes')).toBe('(min-width: 64rem) 13rem, 30vw');
    expect(screen.getByText('New members')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Aki' }).getAttribute('href')).toBe('/member/31');

    // Nothing stands where the stories were: no lead, no card, and no count of what is missing.
    expect(container.querySelector('.rich-body')).toBeNull();
    expect(screen.queryByText('Morning walk')).toBeNull();
    expect(screen.queryByText('0')).toBeNull();
});

test('a stale issue says it is one, and the current issue does not', () => {
    arrive({ issue: issueOf({ isCurrent: false }) });
    expect(screen.getByText('Nothing new today yet — the next post starts a new day here.')).toBeTruthy();

    cleanup();

    arrive({ issue: issueOf() });
    expect(screen.queryByText('Nothing new today yet — the next post starts a new day here.')).toBeNull();
    // The stamp is the instant this day was last put together, not a timetable it did not keep to.
    expect(screen.getByText('Updated August 27, 2026 at 06:00')).toBeTruthy();
    expect(screen.getByText('Posts deleted or made private since are not shown here.')).toBeTruthy();
});

test('a site with nothing published yet offers the way to fill it', () => {
    arrive({ issue: null });

    expect(screen.getByText('Welcome, Viewer.')).toBeTruthy();
    expect(screen.getByText('The first post will appear here the next morning.')).toBeTruthy();
    // Nothing has been put together, so there is no instant to stamp and nothing to page through.
    expect(screen.queryByText(/^Updated /)).toBeNull();
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
