import type { GridImage } from '@/components/image-grid';
import type { ChatFirstUnreadSnapshot, ChatPage, ChatStreamRow } from '@/lib/chat/types';
import type { MessageMember } from '../types';

export interface ConversationMessage extends ChatStreamRow {
    body: string;
    /** The mailbox subject: null for a message written as chat, so the line is left out rather than drawn empty. */
    subject: string | null;
    author: MessageMember | null; // null → the author has withdrawn from the SNS
    isOwn: boolean;
    /** Whether the counterpart has opened this message. Only ever set on the viewer's own; null otherwise. */
    read: boolean | null;
    images: GridImage[];
}

export type ConversationPage = ChatPage<ConversationMessage>;

/** Where the unread boundary stood when the page was rendered — see the divider note in index.tsx. */
export type ConversationUnreadSnapshot = ChatFirstUnreadSnapshot;
