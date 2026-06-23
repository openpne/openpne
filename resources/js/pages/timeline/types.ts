export type TimelinePostVisibility = 'open' | 'members' | 'friends' | 'private';

export interface TimelinePostAuthor {
    id: number;
    name: string;
}

export interface TimelinePostImage {
    id: number;
    url: string; // full bytes (FilePolicy-gated)
    thumbnailUrl: string; // 120×120 square
}

export interface TimelinePostEntry {
    id: number;
    body: string;
    visibility: TimelinePostVisibility;
    hasImages: boolean;
    images: TimelinePostImage[];
    author: TimelinePostAuthor;
    createdAt: string;
}

export interface PaginatedTimelinePosts {
    data: TimelinePostEntry[];
    meta: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
    };
}
