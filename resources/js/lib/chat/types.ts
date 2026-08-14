/**
 * The part of a chat message the stream machinery reads: its identity and its place in the keyset
 * order. Everything else a conversation shows — bodies, authors, attachments — is the page's own.
 */
export interface ChatStreamRow {
    id: number;
    createdAt: string;
    /** This message's position in the keyset order — handed back to ask for the pages around it. */
    cursor: string;
}

/** One slice of the conversation, oldest first, and what lies either side of it. */
export interface ChatPage<M extends ChatStreamRow> {
    messages: M[];
    hasOlder: boolean;
    /** Rows follow this page that the client was not given — only a forward read answers it. */
    hasNewer: boolean;
}

/**
 * Where the unread boundary stood when the page was rendered, for a member; null for a reader who
 * holds no cursor. Fixed for the visit — see the divider note in the page that draws it.
 */
export interface ChatUnreadSnapshot {
    count: number;
    /** The read cursor's `(created_at, id)` tuple, comparable with a message's own `createdAt`. */
    readThrough: { at: string; id: number };
    /** The same position as an opaque pagination cursor — what asks for the page it sits in. */
    cursor: string;
}
