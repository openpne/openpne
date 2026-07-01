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
