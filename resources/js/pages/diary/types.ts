export type DiaryVisibility = 'open' | 'members' | 'friends' | 'private';

export interface DiaryAuthor {
    id: number;
    name: string;
}

export interface DiaryImage {
    id: number;
    url: string; // full bytes (FilePolicy-gated)
    thumbnailUrl: string; // 120×120 square
}

export interface DiarySummary {
    id: number;
    title: string;
    visibility: DiaryVisibility;
    hasImages: boolean; // drives the feed's has-photos marker
    author: DiaryAuthor;
    createdAt: string;
}

export interface DiaryDetail extends DiarySummary {
    body: string;
    images: DiaryImage[];
}

export interface DiaryComment {
    id: number;
    number: number;
    body: string;
    images: DiaryImage[];
    author: DiaryAuthor | null; // null once the author has withdrawn
    createdAt: string;
    deletable: boolean; // viewer-specific, computed server-side
}

export interface PaginatedDiaries {
    data: DiarySummary[];
    meta: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
    };
}
