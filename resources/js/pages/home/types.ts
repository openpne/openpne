import type { GridImage } from '@/components/image-grid';
import type { MentionEntity } from '@/lib/entity-split';
import type { NineTableItem, PageProps } from '@/types';
import type { CommunityActivityEntry } from '../community/activity-row';
import type { EventDetail, MemberRef, TopicDetail } from '../community/types';
import type { DiaryDetail } from '../diary/types';
import type { TimelinePostEntry } from '../timeline/types';
import type { HomeGroup } from '../unified/group-grid';

/**
 * The front page issue (号): one edition of the site, published once a day and identical for every
 * member. **It is for reading**: every story travels whole, in rank order, and how much of a body
 * is shown on screen is the page's decision rather than the payload's.
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
 * One story of an issue, carried whole: drawn with its body, its pictures and its counts rather
 * than as a preview of them. `excerpt` rides along for the places a plain line is wanted.
 */
export type IssueStory =
    | { kind: 'diary'; item: DiaryDetail }
    | { kind: 'timeline'; item: TimelinePostEntry & { excerpt: string } }
    | { kind: 'topic'; item: TopicDetail & { group: BoardScope; excerpt: string } }
    | { kind: 'event'; item: EventDetail & { group: BoardScope; excerpt: string } };

/**
 * One message of a talk excerpt, in the stream's own shape minus what belongs to a live room: no
 * cursor, no reactions, nothing about what the reader may do. `author` is null for a withdrawn
 * member, drawn with the established label.
 */
export interface TalkExcerptMessage {
    id: number;
    author: MemberRef | null;
    body: string;
    /** @mention ranges over the body, ascending and non-overlapping — what EntityText walks. */
    mentions: MentionEntity[];
    createdAt: string;
    /** What was posted with it, gated per file server-side. */
    images: GridImage[];
}

/** A run of talk in one group during the issue's day: how much was said, and the end of it to read. */
export interface TalkBurst {
    group: BoardScope;
    count: number;
    /** The last messages of the run, oldest first — the excerpt, not a summary of it. */
    messages: TalkExcerptMessage[];
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
    /** The days it covers, `Y-m-d` each. `to` is `date`; `from` differs only on a longer stretch. */
    days: { from: string; to: string };
    /** The instants those days were drawn from, `(from, to]`. What the colophon states. */
    window: { from: string; to: string };
    /** Whether the page is showing the freshest issue there could be; a stale one says so below. */
    isCurrent: boolean;
    /** Ranks 1–8, in order. Absent once every story the issue featured has been taken down or
     *  narrowed — the rest of the issue still stands, so it is a missing key, not a missing issue. */
    stories?: IssueStory[];
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
