import type { GridImage } from '@/components/image-grid';
import type { NineTableItem, PageProps } from '@/types';
import type { CommunityActivityEntry } from '../community/activity-row';
import type { EventDetail, MemberRef, TopicDetail } from '../community/types';
import type { DiaryDetail, DiarySummary } from '../diary/types';
import type { TimelinePostEntry } from '../timeline/types';
import type { HomeGroup } from '../unified/group-grid';

/**
 * The front page issue (号): one edition of the site, published once a day and identical for every
 * member. Its shape is what the layout is chosen from, so the payload states the layout rather than
 * carrying a mode: an issue with one content item has neither `features` nor `briefs`, one with two
 * or three has `features`, and one with four or more has `briefs`.
 *
 * Every optional section key is **absent** when it is empty, never `[]`. A section renders exactly
 * when its key is present, so nothing has to decide what an empty list means on screen.
 */

/** An issue as something to link to: which day it covers, its number, and where it is read. */
export interface IssueRef {
    /** The site's calendar day the issue covers, `Y-m-d` — a civil date, never an instant. */
    date: string;
    number: number;
    href: string;
}

/** The group a board entry or a talk burst belongs to, as much of it as a byline draws. */
export interface BoardScope {
    id: number;
    name: string;
    imageUrl: string | null;
}

/**
 * One content item at the top of an issue, carried whole: the story is drawn with its body, its
 * pictures and its link card, not as a preview of them.
 */
export type IssueStory =
    | { kind: 'diary'; item: DiaryDetail }
    | { kind: 'timeline'; item: TimelinePostEntry & { excerpt: string } }
    | { kind: 'topic'; item: TopicDetail & { group: BoardScope; excerpt: string } }
    | { kind: 'event'; item: EventDetail & { group: BoardScope; excerpt: string } };

/** One content item below the fold, in the shape the dashboard's rows already read. */
export type IssueBrief =
    | { kind: 'diary'; item: DiarySummary }
    | { kind: 'timeline'; item: TimelinePostEntry }
    | { kind: 'topic' | 'event'; item: CommunityActivityEntry };

/** A run of talk in one group during the issue's day: how much was said, by whom, and a glimpse. */
export interface TalkBurst {
    group: BoardScope;
    count: number;
    /** Where the run starts, as an instant. */
    since: string;
    /** A bounded sample of who spoke, busiest first. */
    participants: MemberRef[];
    /** A glimpse of what was posted, oldest first. */
    thumbnails: GridImage[];
    href: string;
}

/** An event whose open date is still ahead: the activity row's fields plus the day it falls on. */
export interface UpcomingEvent extends CommunityActivityEntry {
    /** `Y-m-d` civil date, no instant — format with <CivilDate>, never as an instant. */
    openDate: string;
}

export interface Issue extends IssueRef {
    /** When the issue went out, as an instant. */
    publishedAt: string;
    /** Whether `date` is the site's today: a stale issue says so in the colophon. */
    isCurrent: boolean;
    topStory: IssueStory;
    /** Ranks 2–3, present only when the issue has 2–3 content items. */
    features?: IssueStory[];
    /** Ranks 2–8, present only when the issue has 4 or more. */
    briefs?: IssueBrief[];
    talkBursts?: TalkBurst[];
    newcomers?: NineTableItem[];
    newGroups?: HomeGroup[];
    upcomingEvents?: UpcomingEvent[];
}

/** The issue page's own props: `home/issue` at `/` and `home/archive` at a dated URL share them. */
export interface IssuePageProps extends PageProps {
    /** Null only while no issue has ever been published. */
    issue: Issue | null;
    prev: IssueRef | null;
    /** Always null on the current issue — there is nothing after it yet. */
    next: IssueRef | null;
    /** The site's daily publication time, `H:i`. */
    publishTime: string;
}

export interface IssuesPageProps extends PageProps {
    issues: {
        data: IssueRef[];
        meta: {
            currentPage: number;
            lastPage: number;
            perPage: number;
            total: number;
        };
    };
}
