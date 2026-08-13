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
    isOwn: boolean;
    canDelete: boolean;
}

/** One slice of the conversation, oldest first, and whether anything remains further back. */
export interface TalkPage {
    messages: TalkMessage[];
    hasOlder: boolean;
}
