import type { GridImage } from '@/components/image-grid';
import type { MentionEntity } from '@/lib/entity-split';
import type { NineTableItem, PageProps } from '@/types';
import type { CommunityActivityEntry } from '../community/activity-row';
import type { MemberRef } from '../community/types';
import type { HomeGroup } from '../unified/group-grid';

/**
 * Every optional section key is absent when it is empty, never `[]`, so nothing has to decide what
 * an empty list means on screen.
 */
export interface IssueRef {
    /** The site's calendar day the issue covers, `Y-m-d` — a civil date, never an instant. */
    date: string;
    number: number;
    href: string;
}

export interface BoardScope {
    id: number;
    name: string;
    imageUrl: string | null;
}

/**
 * Never a body: the block is a way in (docs/internals/home-issues.md, "Rendering"). `kind` is the
 * byline's grammar rather than a shape switch — a board entry names its group, a post counts replies.
 */
export interface IssueStory {
    kind: 'diary' | 'timeline' | 'topic' | 'event';
    id: number;
    href: string;
    /** A post has no title, so this is the line its author opened with. */
    headline: string;
    /** The lead of the body, plain text and already cut; empty when there is nothing after the
     *  headline. Not markup and not a link — a URL in it reads as the text it is. */
    dek: string;
    /** Null for a withdrawn member, drawn with the established label. */
    author: MemberRef | null;
    /** The group a board entry was posted in; null on a diary or a post. */
    group: BoardScope | null;
    createdAt: string;
    /** Comments, or replies on a post — one number, whichever the kind counts. */
    commentCount: number;
    /** The first picture posted with it. Null is what decides the block's shape, not a missing key. */
    image: GridImage | null;
}

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

export interface TalkBurst {
    group: BoardScope;
    count: number;
    /** The last messages of the run, oldest first — the excerpt, not a summary of it. */
    messages: TalkExcerptMessage[];
    href: string;
}

export interface UpcomingEvent extends CommunityActivityEntry {
    /** `Y-m-d` civil date, no instant — format with <CivilDate>, never as an instant. */
    openDate: string;
}

export interface Issue extends IssueRef {
    /** The days it covers, `Y-m-d` each. `to` is `date`; `from` differs only on a longer stretch. */
    days: { from: string; to: string };
    /** The instants those days were drawn from, `(from, to]`. What the colophon states. */
    window: { from: string; to: string };
    /** Whether the page is showing the freshest issue there could be; a stale one says so below. */
    isCurrent: boolean;
    /** Ranks 1–8, in order: the lead, then the seconds, then the rest. Absent once every story the
     *  issue featured has been taken down or narrowed — the rest of the issue still stands, so it is
     *  a missing key, not a missing issue. */
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
