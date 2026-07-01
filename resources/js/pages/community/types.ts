export interface CommunityCategory {
    id: number;
    name: string;
}

export interface CommunitySummary {
    id: number;
    name: string;
    description: string;
    memberCount: number;
    imageUrl: string | null; // null → CommunityImage renders the id-colored initial badge
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

export interface TopicAuthor {
    id: number;
    name: string;
    imageUrl: string | null; // null → Avatar renders the id-colored initial badge
}

export interface TopicImage {
    id: number;
    url: string; // full-bytes (opens in a new tab)
    thumbnailUrl: string; // 120px square
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
    images: TopicImage[];
    author: TopicAuthor | null;
    createdAt: string;
}

export interface TopicComment {
    id: number;
    number: number;
    body: string;
    images: TopicImage[];
    author: TopicAuthor | null;
    createdAt: string;
    deletable: boolean; // viewer-specific, computed server-side
}

export interface PaginatedTopics {
    data: TopicSummary[];
    meta: PaginationMeta;
}

// The comment thread pager (OpenPNE 3): id-ordered, fixed page size, reversible. `ascending` is the
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
    author: TopicAuthor | null;
    updatedAt: string;
    openDate: string; // ISO 8601 (rendered as a date)
}

export interface EventDetail {
    id: number;
    name: string;
    body: string;
    images: TopicImage[];
    author: TopicAuthor | null;
    createdAt: string;
    openDate: string;
    openDateComment: string;
    area: string;
    applicationDeadline: string | null;
    capacity: number | null;
    participantCount: number;
}

export interface EventParticipant {
    id: number;
    name: string;
    imageUrl: string | null;
}

export interface PaginatedEvents {
    data: EventSummary[];
    meta: PaginationMeta;
}

export interface PaginatedEventParticipants {
    data: EventParticipant[];
    meta: PaginationMeta;
}
