export type DiaryVisibility = 'open' | 'members' | 'friends' | 'private';

/** Minimal author reference: id + name only. Used where the payload carries no avatar (the diary
 *  list `owner`, whose controller sends id/name only). */
export interface DiaryAuthor {
    id: number;
    name: string;
}

/** An author reference that carries an avatar — the diary byline (feed/detail) and comment authors. */
export interface DiaryAvatarAuthor extends DiaryAuthor {
    imageUrl: string | null;
    avatarColor: string | null;
}

export interface DiaryImage {
    id: number;
    url: string; // full bytes (FilePolicy-gated)
    thumbnailUrl: string; // 120×120 square
}

export interface DiarySummary {
    id: number;
    title: string;
    excerpt: string; // plain-text body lead-in (OpenPNE 3 width-108 single line), rendered on rich rows
    visibility: DiaryVisibility;
    commentCount: number;
    hasImages: boolean; // drives the feed's has-photos marker
    thumbnails: string[]; // all attachments' square thumbnails, only eager-loaded for rich rows
    author: DiaryAvatarAuthor;
    createdAt: string;
}

export interface DiaryDetail extends DiarySummary {
    body: string;
    format: string; // BodyFormat: 'plain' | 'op3' | 'markdown'
    bodyHtml: string | null; // server-rendered decoration HTML; null when the body is plain
    images: DiaryImage[];
}

/** The older/newer pager target: identity + title + date (formatDate-compatible ISO string). */
export interface DiaryNeighbor {
    id: number;
    title: string;
    createdAt: string;
}

export interface DiaryComment {
    id: number;
    number: number;
    body: string;
    images: DiaryImage[];
    author: DiaryAvatarAuthor | null; // null once the author has withdrawn
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
