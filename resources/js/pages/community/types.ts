import type { GridImage } from '@/components/image-grid';
import type { LinkCardData } from '@/components/link-card';

export interface CommunityCategory {
    id: number;
    name: string;
}

/** The member a list belongs to: identity plus what it takes to draw their avatar in the chrome. */
export interface MemberRef {
    id: number;
    name: string;
    imageUrl: string | null;
    avatarColor: string | null;
    isAi: boolean;
}

export interface CommunitySummary {
    id: number;
    name: string;
    description: string;
    memberCount: number;
    imageUrl: string | null; // null → CommunityImage renders the neutral initial badge
    category: CommunityCategory | null;
}

export interface CommunityDetail extends CommunitySummary {
    registerPolicy: 'open' | 'approval'; // drives the join-button label
}

export type CommunityRoleSlug = 'member' | 'sub_admin' | 'admin';

export interface CommunityMemberRow {
    id: number; // member id
    name: string;
    imageUrl: string | null;
    avatarColor: string | null;
    isAi: boolean;
    role: CommunityRoleSlug;
}

interface PaginationMeta {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
}

export interface PaginatedCommunities {
    data: CommunitySummary[];
    meta: PaginationMeta;
}

export interface PaginatedCommunityMembers {
    data: CommunityMemberRow[];
    meta: PaginationMeta;
}

/** One conversation on the joined-groups list. `id` is the group's: the row opens its talk. */
export interface TalkRoomRow {
    id: number;
    name: string;
    imageUrl: string | null;
    unread: number;
    muted: boolean;
    // authorIsAi rides beside the name rather than inside it: the preview has no member reference
    // to carry, and the row draws the same chip the talk screen does.
    latest: { body: string; authorName: string | null; authorIsAi: boolean; createdAt: string } | null; // null → nothing said yet
}

export interface PaginatedTalkRooms {
    data: TalkRoomRow[];
    meta: PaginationMeta;
}

export interface TopicAuthor {
    id: number;
    name: string;
    imageUrl: string | null; // null → Avatar renders the neutral initial badge
    avatarColor: string | null;
    isAi: boolean;
}

export interface TopicSummary {
    id: number;
    name: string;
    commentCount: number;
    author: TopicAuthor | null; // null → withdrawn author
    updatedAt: string; // ISO 8601 (last activity; a new comment bumps it)
}

export interface TopicDetail {
    id: number;
    name: string;
    body: string;
    format: string; // BodyFormat: 'plain' | 'op3' | 'markdown'
    bodyHtml: string | null; // server-rendered decoration HTML; null when the body is plain
    images: GridImage[];
    linkCard: LinkCardData | null; // first URL in the body, previewed; null when there is none
    author: TopicAuthor | null;
    createdAt: string;
}

export interface TopicComment {
    id: number;
    number: number;
    body: string;
    images: GridImage[];
    author: TopicAuthor | null;
    createdAt: string;
    deletable: boolean; // viewer-specific, computed server-side
}

export interface PaginatedTopics {
    data: TopicSummary[];
    meta: PaginationMeta;
}

// The comment thread pager: id-ordered, fixed page size, reversible. `ascending` is the
// current order; olderPage/newerPage are null when that direction has no more pages.
export interface TopicThread {
    comments: TopicComment[];
    total: number;
    page: number;
    lastPage: number;
    ascending: boolean;
    hasOlder: boolean;
    hasNewer: boolean;
    olderPage: number | null;
    newerPage: number | null;
}

// Event comment / thread shapes are identical to the topic board's.
export type EventComment = TopicComment;
export type EventThread = TopicThread;

export interface EventSummary {
    id: number;
    name: string;
    commentCount: number;
    participantCount: number;
    author: TopicAuthor | null;
    updatedAt: string; // ISO 8601 datetime
    openDate: string; // Y-m-d civil date, no instant — format with civilDate, never as an instant
}

export interface EventDetail {
    id: number;
    name: string;
    body: string;
    format: string; // BodyFormat: 'plain' | 'op3' | 'markdown'
    bodyHtml: string | null; // server-rendered decoration HTML; null when the body is plain
    images: GridImage[];
    linkCard: LinkCardData | null; // first URL in the body, previewed; null when there is none
    author: TopicAuthor | null;
    createdAt: string; // ISO 8601 datetime
    openDate: string; // Y-m-d (date only)
    openDateComment: string;
    area: string;
    applicationDeadline: string | null; // Y-m-d (date only) or null
    capacity: number | null;
    participantCount: number;
}

export interface EventParticipant {
    id: number;
    name: string;
    imageUrl: string | null;
    avatarColor: string | null;
    isAi: boolean;
}

export interface PaginatedEvents {
    data: EventSummary[];
    meta: PaginationMeta;
}

export interface PaginatedEventParticipants {
    data: EventParticipant[];
    meta: PaginationMeta;
}
