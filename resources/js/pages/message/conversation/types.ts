import type { ChatPage, ChatStreamRow } from '@/lib/chat/types';
import type { MessageImage, MessageMember } from '../types';

export interface ConversationMessage extends ChatStreamRow {
    body: string;
    /** The mailbox subject: null for a message written as chat, so the line is left out rather than drawn empty. */
    subject: string | null;
    author: MessageMember | null; // null → the author has withdrawn from the SNS
    isOwn: boolean;
    /** Whether the counterpart has opened this message. Only ever set on the viewer's own; null otherwise. */
    read: boolean | null;
    images: MessageImage[];
}

export type ConversationPage = ChatPage<ConversationMessage>;
