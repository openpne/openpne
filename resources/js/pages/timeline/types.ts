import type { GridImage } from '@/components/image-grid';
import type { LinkCardData } from '@/components/link-card';
import type { MentionEntity, TagEntity } from '@/lib/entity-split';

export type TimelinePostVisibility = 'open' | 'members' | 'friends' | 'private';

export interface TimelinePostAuthor {
    id: number;
    name: string;
    imageUrl: string | null;
    avatarColor: string | null;
    isAi: boolean;
}

export interface TimelinePostEntry {
    id: number;
    body: string;
    visibility: TimelinePostVisibility;
    hasImages: boolean;
    replyCount: number; // 0 on replies and single route-bound posts (see TimelinePostSerializer)
    images: GridImage[];
    mentions: MentionEntity[]; // @mention ranges over the body, in body order
    tags: TagEntity[]; // #hashtag ranges over the body, in body order
    linkCard: LinkCardData | null; // first URL in the body, previewed; null when there is none
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
