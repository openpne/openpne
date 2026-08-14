import type { ChatPage, ChatStreamRow, ChatUnreadSnapshot } from '@/lib/chat/types';
import type { MentionEntity } from '@/lib/entity-split';
import type { MemberRef } from '@/pages/community/types';

export interface TalkMessage extends ChatStreamRow {
    body: string;
    author: MemberRef | null; // null → the author has withdrawn from the SNS
    /** @mention ranges over the body, ascending and non-overlapping. Talk parses no hashtags. */
    mentions: MentionEntity[];
    /** Attached images in slot order — up to MAX_POST_IMAGES from the composer, N from migrated content. */
    images: { id: number; url: string; thumbnailUrl: string }[];
    isOwn: boolean;
    canDelete: boolean;
}

export type TalkPage = ChatPage<TalkMessage>;

/** Where the unread boundary stood when the page was rendered — see the divider note in index.tsx. */
export type TalkUnreadSnapshot = ChatUnreadSnapshot;
