interface PaginationMeta {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
}

export interface MessageMember {
    id: number;
    name: string;
    imageUrl: string | null; // null → Avatar renders the neutral initial badge
    avatarColor: string | null;
}

export interface MessageRow {
    id: number;
    counterparty: MessageMember | null; // null → withdrawn member
    subject: string;
    date: string; // ISO 8601
    unread: boolean;
}

export interface PaginatedMessages {
    data: MessageRow[];
    meta: PaginationMeta;
}

/**
 * One conversation on the list. There is no id: the row is addressed by its counterpart, and a null
 * one is the withdrawn bucket every departed member's messages fall into.
 */
export interface ConversationRowData {
    counterpart: MessageMember | null;
    unread: number;
    latest: { body: string; createdAt: string };
}

export interface PaginatedConversations {
    data: ConversationRowData[];
    meta: PaginationMeta;
}

export interface MessageImage {
    id: number;
    url: string; // full-bytes (opens in a new tab)
    thumbnailUrl: string; // 120px square
}

export interface MessageDraftForm {
    id: number;
    subject: string;
    body: string;
    recipient: MessageMember | null; // fixed when the draft was created; null → withdrawn
    images: MessageImage[]; // current attachments, each removable by id
}
