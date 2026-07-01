interface PaginationMeta {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
}

export type MessageBoxSlug = 'receive' | 'sent' | 'draft' | 'trash';

export interface MessageMember {
    id: number;
    name: string;
    imageUrl: string | null; // null → Avatar renders the id-colored initial badge
}

export interface MessageRow {
    id: number;
    counterparty: MessageMember | null; // null → withdrawn member
    subject: string;
    date: string; // ISO 8601 (delivery time for the inbox, authoring/trash time otherwise)
    unread: boolean; // only ever true in the inbox
}

export interface PaginatedMessages {
    data: MessageRow[];
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

export interface MessageDetail {
    id: number;
    subject: string;
    body: string;
    createdAt: string; // ISO 8601
    images: MessageImage[];
    counterparties: MessageMember[]; // To when the viewer sent it, the single From otherwise
    viewerIsSender: boolean;
    box: MessageBoxSlug;
    previousId: number | null; // adjacent messages within the box, null at the ends
    nextId: number | null;
}
