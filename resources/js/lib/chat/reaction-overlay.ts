import type { ChatReactionChip } from './types';

/** Two verbs rather than a flip, as the two endpoints are. */
export type ReactionOp = 'add' | 'remove';

export interface PendingReaction {
    messageId: number;
    emoji: string;
    op: ReactionOp;
}

/**
 * Overlaying rather than writing the guess into the list is what makes a refusal harmless: it takes
 * its own entry away and nothing else, leaving a reaction the poll delivered meanwhile. Keyed by
 * (message, emoji), which is also the in-flight rule: a second tap on the same chip is ignored
 * rather than queued.
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
 * Only the entry this tap made is taken: an answer arriving after another chip's tap has gone out
 * must not take that one's guess off the screen with it.
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
 * Applied both while the tap is out and again when the write says it landed, which is safe because
 * it is idempotent: it moves the viewer's own line only when the row disagrees with it. A delta
 * rather than the aggregate the write answered with, so changes the poll delivered meanwhile stand.
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
