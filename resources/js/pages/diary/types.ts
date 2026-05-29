export type DiaryVisibility = 'open' | 'members' | 'friends' | 'private';

export interface DiaryAuthor {
    id: number;
    name: string;
}

export interface DiarySummary {
    id: number;
    title: string;
    visibility: DiaryVisibility;
    author: DiaryAuthor;
    createdAt: string;
}

export interface DiaryDetail extends DiarySummary {
    body: string;
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
