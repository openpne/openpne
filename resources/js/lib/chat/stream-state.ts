import { applyReactionOutcome, type ReactionOp } from './reaction-overlay.ts';
import type { ChatPage, ChatStreamRow } from './types';

/**
 * Every way a message can arrive goes through one of the merges below, so the list obeys the same
 * order, dedupe and tombstone rules however it was assembled (docs/internals/group-talk.md,
 * "Ordering is the `(created_at, id)` tuple"). A message is its id: the same row arriving twice
 * replaces the copy held, so a re-read carrying a changed `canDelete` wins.
 */
/**
 * `latest` ends at the newest message and is polled; `history` is a slice behind it, stepped forward
 * by "load newer" and never polled (docs/internals/group-talk.md, "Two windows, never mixed").
 */
export type ChatWindow = { kind: 'latest' } | { kind: 'history'; hasNewer: boolean };

export interface ChatStreamState<M extends ChatStreamRow> {
    /** Ascending by (createdAt, id). */
    messages: M[];
    hasOlder: boolean;
    /** Ids deleted in this session. Never re-admitted, whatever a later response carries. */
    deleted: ReadonlySet<number>;
    window: ChatWindow;
    /** Which list a response was asked of — see {@link applied}. Moves on every window change. */
    generation: number;
    /**
     * What the next reaction poll asks after; undefined for a surface with no reactions. It lives
     * beside the list so {@link applied} guards it too, and a response the window change made
     * worthless cannot move it past changes the list never saw.
     */
    reactionsVersion?: number;
}

/**
 * Every read is issued against the generation the list stood at, and a response whose generation has
 * passed is dropped rather than merged (docs/internals/group-talk.md, "Two windows, never mixed").
 */
export function applied<M extends ChatStreamRow>(
    state: ChatStreamState<M>,
    at: number,
    fold: (current: ChatStreamState<M>) => ChatStreamState<M>,
): ChatStreamState<M> {
    return at === state.generation ? fold(state) : state;
}

/**
 * The generation moves only when a read is applied, so a second count orders the reads that ask for
 * a window change: the last intent wins (docs/internals/group-talk.md, "Two windows, never mixed").
 */
export interface ChatIntents {
    claimed: number;
}

export function newIntents(): ChatIntents {
    return { claimed: 0 };
}

/** Claiming retires every intent still out; the epoch returned is what to check back against. */
export function claimIntent(intents: ChatIntents): number {
    intents.claimed += 1;

    return intents.claimed;
}

/** Retire what is out without claiming a move of your own. */
export function retireIntents(intents: ChatIntents): void {
    claimIntent(intents);
}

export function isCurrentIntent(intents: ChatIntents, epoch: number): boolean {
    return intents.claimed === epoch;
}

/**
 * An unparseable stamp sorts first rather than poisoning the comparison: NaN compares false against
 * everything, which would make the ordering intransitive.
 */
export function instantOf(createdAt: string): number {
    const at = Date.parse(createdAt);

    return Number.isNaN(at) ? 0 : at;
}

function orderOf(message: ChatStreamRow): [number, number] {
    return [instantOf(message.createdAt), message.id];
}

function byTuple(a: ChatStreamRow, b: ChatStreamRow): number {
    const [aAt, aId] = orderOf(a);
    const [bAt, bId] = orderOf(b);

    return aAt === bAt ? aId - bId : aAt - bAt;
}

function sameWindow(a: ChatWindow, b: ChatWindow): boolean {
    if (a.kind === 'latest' || b.kind === 'latest') {
        return a.kind === b.kind;
    }

    return a.hasNewer === b.hasNewer;
}

function windowAfter<M extends ChatStreamRow>(page: ChatPage<M>): ChatWindow {
    return page.hasNewer ? { kind: 'history', hasNewer: true } : { kind: 'latest' };
}

/** `hasOlder` is decided by the caller, since only it knows which end it read from. */
function merge<M extends ChatStreamRow>(
    state: ChatStreamState<M>,
    arriving: readonly M[],
    hasOlder: boolean,
    window = state.window,
    generation = state.generation,
): ChatStreamState<M> {
    // Returning the same value matters: a new `messages` reference re-runs the page's pin effect,
    // and a scroll re-issued every tick fights iOS's keyboard pan.
    if (arriving.length === 0 && hasOlder === state.hasOlder && sameWindow(window, state.window) && generation === state.generation) {
        return state;
    }
    const held = new Map(state.messages.map((message) => [message.id, message]));
    for (const message of arriving) {
        held.set(message.id, message);
    }

    const messages = [...held.values()].filter((message) => !state.deleted.has(message.id)).sort(byTuple);

    return { messages, hasOlder, deleted: state.deleted, window, generation, reactionsVersion: state.reactionsVersion };
}

/**
 * The generation moves with the replacement, which retires the reads the old list had out, and the
 * tombstones are the one thing carried over (docs/internals/group-talk.md, "Two windows, never mixed").
 */
function replace<M extends ChatStreamRow>(state: ChatStreamState<M>, page: ChatPage<M>, window: ChatWindow): ChatStreamState<M> {
    const messages = page.messages.filter((message) => !state.deleted.has(message.id)).sort(byTuple);

    return {
        messages,
        hasOlder: page.hasOlder,
        deleted: state.deleted,
        window,
        generation: state.generation + 1,
        // Carried across the change: how far the reactions have been read is a fact about the
        // conversation, not about the stretch of it on screen.
        reactionsVersion: state.reactionsVersion,
    };
}

/**
 * The window is read off `hasNewer` rather than assumed live, because a `?m=` deep link is rendered
 * inside history (docs/internals/group-talk.md, "Two windows, never mixed").
 */
export function initial<M extends ChatStreamRow>(page: ChatPage<M>, reactionsVersion?: number): ChatStreamState<M> {
    const empty: ChatStreamState<M> = {
        messages: [],
        hasOlder: false,
        deleted: new Set(),
        window: { kind: 'latest' },
        generation: 0,
        reactionsVersion,
    };

    return merge(empty, page.messages, page.hasOlder, windowAfter(page));
}

/** It reads forward only, so it learns nothing about the far end and leaves `hasOlder` alone. */
export function mergeAfter<M extends ChatStreamRow>(state: ChatStreamState<M>, page: ChatPage<M>): ChatStreamState<M> {
    return merge(state, page.messages, state.hasOlder);
}

/**
 * The poll's answer when there was no watermark to ask after and it asked for the newest page
 * instead. Its `hasOlder` is adopted only while the list is empty, since it covers that page alone.
 */
export function mergeLatest<M extends ChatStreamRow>(state: ChatStreamState<M>, page: ChatPage<M>): ChatStreamState<M> {
    return merge(state, page.messages, state.messages.length === 0 ? page.hasOlder : state.hasOlder);
}

/** "Load older": the response is the authority on whether anything remains behind it. */
export function mergeBefore<M extends ChatStreamRow>(state: ChatStreamState<M>, page: ChatPage<M>): ChatStreamState<M> {
    return merge(state, page.messages, page.hasOlder);
}

/**
 * Held to the live window rather than to a generation: the message belongs at the foot of any list
 * that ends at the newest one (docs/internals/group-talk.md, "Two windows, never mixed").
 */
export function mergeSent<M extends ChatStreamRow>(state: ChatStreamState<M>, message: M): ChatStreamState<M> {
    if (state.window.kind !== 'latest') {
        return state;
    }

    return merge(state, [message], state.hasOlder);
}

/**
 * The page after the last row on screen is contiguous with it, so it may be merged; nothing beyond
 * it puts the list back in the latest window (docs/internals/group-talk.md, "Two windows, never mixed").
 */
export function mergeNewer<M extends ChatStreamRow>(state: ChatStreamState<M>, page: ChatPage<M>): ChatStreamState<M> {
    const window = windowAfter(page);
    // Catching up with the newest message is a window change like any other, so it retires the reads
    // the history window had out and lets the poll start from a list it can trust.
    const generation = window.kind === 'latest' ? state.generation + 1 : state.generation;

    return merge(state, page.messages, state.hasOlder, window, generation);
}

/** A boundary page contiguous through to the newest ends up in the latest window. */
export function enterHistory<M extends ChatStreamRow>(state: ChatStreamState<M>, page: ChatPage<M>): ChatStreamState<M> {
    return replace(state, page, windowAfter(page));
}

export function enterLatest<M extends ChatStreamRow>(state: ChatStreamState<M>, page: ChatPage<M>): ChatStreamState<M> {
    return replace(state, page, { kind: 'latest' });
}

/**
 * Not tied to a generation: a deletion is a fact about the conversation rather than about a page of
 * it, and holds wherever the reader is standing.
 */
export function markDeleted<M extends ChatStreamRow>(state: ChatStreamState<M>, id: number): ChatStreamState<M> {
    const deleted = new Set(state.deleted);
    deleted.add(id);

    return {
        messages: state.messages.filter((message) => message.id !== id),
        hasOlder: state.hasOlder,
        deleted,
        window: state.window,
        generation: state.generation,
        reactionsVersion: state.reactionsVersion,
    };
}

/**
 * Update-only: a row whose id is not already held is dropped rather than inserted, which keeps the
 * list one contiguous stretch. The watermark moves whether or not anything applied, because it is
 * the server's account of what has been reported.
 */
export function mergeTouched<M extends ChatStreamRow>(
    state: ChatStreamState<M>,
    rows: readonly M[],
    reactionsVersion?: number,
): ChatStreamState<M> {
    const held = new Set(state.messages.map((message) => message.id));
    const applicable = rows.filter((row) => held.has(row.id));
    const merged = applicable.length === 0 ? state : merge(state, applicable, state.hasOlder);

    if (reactionsVersion === undefined || reactionsVersion === merged.reactionsVersion) {
        return merged;
    }

    return { ...merged, reactionsVersion };
}

/**
 * The move applies to the row as it stands now, not to the aggregate the write answered with, and
 * only while the watermark still stands where the tap was sent. Once it has moved the poll owns the
 * row, including the viewer's own flag, and nothing is lost: a poll past the write's version
 * delivered its outcome already.
 */
export function applyReaction<M extends ChatStreamRow>(
    state: ChatStreamState<M>,
    id: number,
    emoji: string,
    op: ReactionOp,
    versionAtSend?: number,
): ChatStreamState<M> {
    if (state.reactionsVersion !== versionAtSend) {
        return state;
    }

    if (!state.messages.some((message) => message.id === id)) {
        return state;
    }

    return {
        ...state,
        messages: state.messages.map((message) =>
            message.id === id ? { ...message, reactions: applyReactionOutcome(message.reactions ?? [], emoji, op) } : message,
        ),
    };
}

/** The newest message's cursor — what the poll asks after. Undefined while the list is empty. */
export function watermark<M extends ChatStreamRow>(state: ChatStreamState<M>): string | undefined {
    return state.messages[state.messages.length - 1]?.cursor;
}

/** The oldest message's cursor — what "load older" asks before. Undefined while the list is empty. */
export function oldestBoundary<M extends ChatStreamRow>(state: ChatStreamState<M>): string | undefined {
    return state.messages[0]?.cursor;
}
