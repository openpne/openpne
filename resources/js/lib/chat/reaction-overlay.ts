import type { ChatReactionChip } from './types';

/** What a tap asked for. Two verbs rather than a flip, as the two endpoints are. */
export type ReactionOp = 'add' | 'remove';

/** One tap still on the wire. */
export interface PendingReaction {
    messageId: number;
    emoji: string;
    op: ReactionOp;
}

/**
 * The taps whose answers have not come back yet, drawn over the chips the stream holds.
 *
 * Overlaying rather than writing the guess into the list is what makes the failure case harmless: a
 * tap that is refused takes its own entry away and nothing else, so a reaction someone *else* added
 * while it was out — arriving through the poll, into the list underneath — is still there
 * afterwards. Snapshotting the row and restoring it on failure would take that away with it.
 *
 * Keyed by (message, emoji), which is also the rule for what may be in flight: while a tap on one
 * chip is out, another on the same chip is ignored rather than queued. A round trip is short, the
 * pair is idempotent at the server, and the alternative — a queue — would leave the member watching
 * a chip they have stopped pointing at settle through states they no longer want.
 */
export type PendingReactions = ReadonlyMap<string, PendingReaction>;

/** NUL, because an emoji is an arbitrary short string and this must not collide with one. */
const KEY_SEPARATOR = '\u0000';

export function pendingKey(messageId: number, emoji: string): string {
    return `${messageId}${KEY_SEPARATOR}${emoji}`;
}

export function noPending(): PendingReactions {
    return new Map();
}

/** Whether this chip already has a tap out — the one thing that refuses a new one. */
export function isPending(pending: PendingReactions, messageId: number, emoji: string): boolean {
    return pending.has(pendingKey(messageId, emoji));
}

export function withPending(pending: PendingReactions, messageId: number, emoji: string, op: ReactionOp): PendingReactions {
    const next = new Map(pending);
    next.set(pendingKey(messageId, emoji), { messageId, emoji, op });

    return next;
}

/**
 * Settle one tap, however it went. Only the entry it made is taken: an answer that arrives after
 * another chip's tap has gone out must not take that one's guess off the screen with it.
 */
export function withoutPending(pending: PendingReactions, messageId: number, emoji: string): PendingReactions {
    const key = pendingKey(messageId, emoji);
    if (!pending.has(key)) {
        return pending;
    }
    const next = new Map(pending);
    next.delete(key);

    return next;
}

/** One message's chips as they should be drawn: what the server said, plus what is still out. */
export function chipsWithPending(chips: ChatReactionChip[], pending: PendingReactions, messageId: number): ChatReactionChip[] {
    let drawn = chips;
    for (const tap of pending.values()) {
        if (tap.messageId === messageId) {
            drawn = applyReactionOutcome(drawn, tap.emoji, tap.op);
        }
    }

    return drawn;
}

/**
 * One tap applied to a chip row: the guess while it is out, and the same move again when the write
 * comes back saying it landed. Both, because it is idempotent — it asks what the row says about the
 * viewer's own emoji and only moves it when it disagrees, so applying it twice is applying it once.
 *
 * That is what lets a write be folded in as this delta rather than as the aggregate it answered
 * with. The answer describes the moment the server wrote, which may be several changes behind the
 * row on screen by the time it arrives: the poll delivers everyone's changes and moves the watermark
 * past them, so standing the row on a late answer's snapshot would put back counts nothing will ask
 * for again. A delta moves only the viewer's own line and leaves what arrived meanwhile alone.
 */
export function applyReactionOutcome(chips: ChatReactionChip[], emoji: string, op: ReactionOp): ChatReactionChip[] {
    const held = chips.find((chip) => chip.emoji === emoji);

    if (op === 'add') {
        if (held === undefined) {
            // Last, where the server puts one too: chips are ordered by when the emoji first appeared.
            return [...chips, { emoji, count: 1, mine: true }];
        }

        return held.mine ? chips : chips.map((chip) => (chip.emoji === emoji ? { ...chip, count: chip.count + 1, mine: true } : chip));
    }

    if (held === undefined || !held.mine) {
        return chips;
    }

    return held.count <= 1
        ? chips.filter((chip) => chip.emoji !== emoji)
        : chips.map((chip) => (chip.emoji === emoji ? { ...chip, count: chip.count - 1, mine: false } : chip));
}
