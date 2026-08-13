import type { MentionEntity } from '@/lib/entity-split';
import type { MemberRef } from '@/pages/community/types';

export interface TalkMessage {
    id: number;
    body: string;
    createdAt: string;
    /** This message's position in the keyset order — handed back to ask for the pages around it. */
    cursor: string;
    author: MemberRef | null; // null → the author has withdrawn from the SNS
    /** @mention ranges over the body, ascending and non-overlapping. Talk parses no hashtags. */
    mentions: MentionEntity[];
    /** Attached images in slot order — up to MAX_POST_IMAGES from the composer, N from migrated content. */
    images: { id: number; url: string; thumbnailUrl: string }[];
    isOwn: boolean;
    canDelete: boolean;
}

/** One slice of the conversation, oldest first, and what lies either side of it. */
export interface TalkPage {
    messages: TalkMessage[];
    hasOlder: boolean;
    /** Rows follow this page that the client was not given — only a forward read answers it. */
    hasNewer: boolean;
}

/**
 * Where the unread boundary stood when the page was rendered, for a member; null for a reader who
 * holds no cursor. Fixed for the visit — see the divider note in index.tsx.
 */
export interface TalkUnreadSnapshot {
    count: number;
    /** The read cursor's `(created_at, id)` tuple, comparable with a message's own `createdAt`. */
    readThrough: { at: string; id: number };
    /** The same position as an opaque pagination cursor — what asks for the page it sits in. */
    cursor: string;
}
