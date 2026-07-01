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
