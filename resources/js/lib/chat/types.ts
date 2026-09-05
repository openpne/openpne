export interface ChatReactionChip {
    emoji: string;
    count: number;
    mine: boolean;
}

/** Everything else a conversation shows — bodies, authors, attachments — is the page's own. */
export interface ChatStreamRow {
    id: number;
    createdAt: string;
    /** This message's position in the keyset order — handed back to ask for the pages around it. */
    cursor: string;
    /** Optional because a surface may have no reactions at all: a direct message never carries them. */
    reactions?: ChatReactionChip[];
}

/** Oldest first. */
export interface ChatPage<M extends ChatStreamRow> {
    messages: M[];
    hasOlder: boolean;
    /** Rows follow this page that the client was not given — only a forward read answers it. */
    hasNewer: boolean;
    /**
     * Messages whose reactions changed since the watermark the read carried, in version order.
     * Present only when one was sent, so a poll that asks nothing is answered as it always was.
     */
    touched?: M[];
    /** The reaction watermark to come back with. */
    reactionsVersion?: number;
}

/**
 * Where the unread boundary stood when the page was rendered and fixed for the visit; null for a
 * reader who holds no cursor.
 */
export interface ChatUnreadSnapshot {
    count: number;
    /** The read cursor's `(created_at, id)` tuple, comparable with a message's own `createdAt`. */
    readThrough: { at: string; id: number };
    /** The same position as an opaque pagination cursor — what asks for the page it sits in. */
    cursor: string;
}

/**
 * The same, for a conversation that holds no cursor to read a boundary from: the position is the
 * oldest message still waiting. Null when nothing is — there is no row to name.
 */
export interface ChatFirstUnreadSnapshot {
    count: number;
    /** The oldest unread message's own `(created_at, id)` tuple. */
    firstUnread: { at: string; id: number };
    /** The same position as an opaque pagination cursor — what asks for the page it sits in. */
    cursor: string;
}

/**
 * A `readThrough` position is the last row already read; a `firstUnread` position is the first row
 * not read.
 */
export type ChatUnreadBoundary = { kind: 'readThrough'; at: string; id: number } | { kind: 'firstUnread'; at: string; id: number };
