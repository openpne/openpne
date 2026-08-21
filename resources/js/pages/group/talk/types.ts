import type { GridImage } from '@/components/image-grid';
import type { LinkCardData } from '@/components/link-card';
import type { ChatPage, ChatReactionChip, ChatStreamRow, ChatUnreadSnapshot } from '@/lib/chat/types';
import type { MentionEntity } from '@/lib/entity-split';
import type { MemberRef } from '@/pages/community/types';

/**
 * The message this one answers, as the server reads it now (GroupMessageSerializer): null when it
 * answers nothing, a live reference the row draws a header from, or `{ deleted: true }` when the id
 * names a row that is gone. `excerpt` is payload-bounded server-side — the visible cut is the
 * client's clip — and may be the "Image" stand-in. One level travels: a parent that is itself a
 * reply describes only its own text.
 */
export type TalkReplyReference =
    | { deleted: false; id: number; cursor: string; author: MemberRef | null; excerpt: string; thumbnailUrl: string | null }
    | { deleted: true };

export interface TalkMessage extends ChatStreamRow {
    body: string;
    author: MemberRef | null; // null → the author has withdrawn from the SNS
    /** @mention ranges over the body, ascending and non-overlapping. Talk parses no hashtags. */
    mentions: MentionEntity[];
    /** Attached images in slot order — up to MAX_POST_IMAGES from the composer, N from migrated content. */
    images: GridImage[];
    /** First URL in the body, previewed; null when there is none, or while it is still being fetched. */
    linkCard: LinkCardData | null;
    /** The emoji on this message, in the order they first appeared. Always sent, empty for none. */
    reactions: ChatReactionChip[];
    /** The answered message, or null. A reply always starts a fresh turn (lib/chat/message-grouping). */
    inReplyTo: TalkReplyReference | null;
    isOwn: boolean;
    canDelete: boolean;
}

/** Who holds one emoji on a message: the exact count, and at most MessageReactors::PER_EMOJI names. */
export interface TalkReactorGroup {
    emoji: string;
    count: number;
    members: MemberRef[];
}

export type TalkPage = ChatPage<TalkMessage>;

/** Where the unread boundary stood when the page was rendered — see the divider note in index.tsx. */
export type TalkUnreadSnapshot = ChatUnreadSnapshot;

/**
 * What the reader missed while they were away, as the catch-up card states it. Shipped only for a
 * backlog past the server's threshold, so the prop is absent rather than empty on an ordinary visit
 * (App\Features\GroupTalk\Queries\TalkAbsenceDigest).
 */
export interface TalkUnreadDigest {
    /** The snapshot's own count — the same number the divider and the jump stand for. */
    count: number;
    /** Where the backlog starts: the instant the reader had last caught up to. */
    since: string;
    /** Who did the talking, busiest first. A bounded sample of the backlog, never its full roster. */
    participants: MemberRef[];
    /** A glimpse of what was posted, oldest first. Empty when nothing readable was attached. */
    thumbnails: GridImage[];
}
