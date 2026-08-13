import type { TalkMessage, TalkPage } from './types';

/**
 * What a talk page is showing, as a value. Every way a message can arrive — the initial page, either
 * direction of the poll, "load older", the composer's own send — goes through one of the merges
 * below, so the list obeys the same three rules however it was assembled.
 *
 * 1. **Order is always the `(created_at, id)` tuple**, re-established on every merge rather than
 *    assumed from arrival order. Responses complete in whatever order the network gives them: a send
 *    and a poll are in flight together by design, and appending each as it lands would leave the list
 *    out of order with no later merge to correct it — the watermark would then be taken from a row
 *    that is not the newest, and the dedupe would swallow the re-fetch that would have healed it.
 * 2. **A message is its id.** The same row arriving twice replaces the copy held, so a re-read that
 *    carries a changed `canDelete` wins over the stale one.
 * 3. **A deletion is a tombstone for the session.** A poll that was already in flight when the
 *    delete landed answers from a snapshot that still contains the row; without the tombstone it
 *    would put it back, and nothing afterwards would take it away again.
 */
/**
 * Which stretch of the conversation the list is showing.
 *
 * `latest` is the page as it has always worked: it ends at the newest message, so the poll can keep
 * it there and reaching the foot means having read everything. `history` is the slice the unread
 * jump opens on — somewhere behind the newest, with a "load newer" step forward and no poll, because
 * a poll would append messages that do not follow the last row on screen.
 *
 * The two are never mixed. A window change replaces the list outright; only a page contiguous with
 * what is already held is ever merged in. A list assembled from both would show a hole as if it were
 * a conversation.
 */
export type TalkWindow = { kind: 'latest' } | { kind: 'history'; hasNewer: boolean };

export interface TalkStreamState {
    /** Ascending by (createdAt, id). */
    messages: TalkMessage[];
    hasOlder: boolean;
    /** Ids deleted in this session. Never re-admitted, whatever a later response carries. */
    deleted: ReadonlySet<number>;
    window: TalkWindow;
    /** Which list a response was asked of — see {@link applied}. Moves on every window change. */
    generation: number;
}

/**
 * Fold a response into the list that asked for it, or throw it away.
 *
 * Reads are in flight while the window changes under them: the poll is on the wire when the reader
 * taps the unread banner, and either answer can land first. Merging the poll's afterwards would
 * splice rows from the live end into a slice of history with an unfetched stretch between them —
 * drawn as one continuous conversation, and past healing, because the next watermark would be taken
 * from beyond the gap and "load newer" would never ask for it.
 *
 * So every read is issued against the generation the list stood at, and every window change moves
 * that generation on. A response whose generation has passed describes a page the reader is no
 * longer on, and the only safe thing to do with it is nothing.
 */
export function applied(
    state: TalkStreamState,
    at: number,
    fold: (current: TalkStreamState) => TalkStreamState,
): TalkStreamState {
    return at === state.generation ? fold(state) : state;
}

/**
 * The other half of the same question, for the reads that *cause* a window change rather than land
 * in one. The generation only moves when such a read is applied, so between asking for a jump and
 * getting its page there is nothing to tell a later decision from an earlier one — and in that gap
 * the reader can tap another jump, or write.
 *
 * **The last intent wins.** A jump retires the jump before it, and a send retires a jump the reader
 * has since thought better of: writing always puts them back at the live end, so a page fetched for
 * a move they have moved on from must not replace the list under the message they just wrote.
 */
export interface TalkIntents {
    claimed: number;
}

export function newIntents(): TalkIntents {
    return { claimed: 0 };
}

/** Claim the newest intent, retiring every one still out. Returns the epoch to check back against. */
export function claimIntent(intents: TalkIntents): number {
    intents.claimed += 1;

    return intents.claimed;
}

/** Retire what is out without claiming a move of your own — what writing does. */
export function retireIntents(intents: TalkIntents): void {
    claimIntent(intents);
}

/** Whether this navigation's answer is still the one the reader is waiting for. */
export function isCurrentIntent(intents: TalkIntents, epoch: number): boolean {
    return intents.claimed === epoch;
}

/**
 * The instant half of the ordering tuple, in milliseconds. An unparseable stamp sorts first rather
 * than poisoning the comparison — NaN compares false against everything, which would make the
 * ordering intransitive and the sort result arbitrary.
 */
export function instantOf(createdAt: string): number {
    const at = Date.parse(createdAt);

    return Number.isNaN(at) ? 0 : at;
}

/** Sort key: the instant, with the id breaking a tie inside the same second. */
function orderOf(message: TalkMessage): [number, number] {
    return [instantOf(message.createdAt), message.id];
}

function byTuple(a: TalkMessage, b: TalkMessage): number {
    const [aAt, aId] = orderOf(a);
    const [bAt, bId] = orderOf(b);

    return aAt === bAt ? aId - bId : aAt - bAt;
}

function sameWindow(a: TalkWindow, b: TalkWindow): boolean {
    if (a.kind === 'latest' || b.kind === 'latest') {
        return a.kind === b.kind;
    }

    return a.hasNewer === b.hasNewer;
}

/** The window a forward read leaves behind: still short of the newest, or caught up with it. */
function windowAfter(page: TalkPage): TalkWindow {
    return page.hasNewer ? { kind: 'history', hasNewer: true } : { kind: 'latest' };
}

/**
 * Fold messages into the list: later copies of an id win, tombstoned ids are dropped, and the result
 * is sorted. `hasOlder` is decided by the caller, since only it knows which end it read from.
 */
function merge(
    state: TalkStreamState,
    arriving: readonly TalkMessage[],
    hasOlder: boolean,
    window = state.window,
    generation = state.generation,
): TalkStreamState {
    // An idle poll answers with nothing; the state it would rebuild is the state it was given.
    // Returning the same value matters: every new `messages` reference re-runs the page's pin
    // effect, and a scroll re-issued every tick fights iOS's keyboard pan (see index.tsx).
    if (arriving.length === 0 && hasOlder === state.hasOlder && sameWindow(window, state.window) && generation === state.generation) {
        return state;
    }
    const held = new Map(state.messages.map((message) => [message.id, message]));
    for (const message of arriving) {
        held.set(message.id, message);
    }

    const messages = [...held.values()].filter((message) => !state.deleted.has(message.id)).sort(byTuple);

    return { messages, hasOlder, deleted: state.deleted, window, generation };
}

/**
 * Throw the list away and stand on $page instead — how every window change lands. The generation
 * moves with it, which is what retires the reads the old list had out. The tombstones are the one
 * thing carried over: a session's deletions outlive the stretch of conversation they were made in.
 */
function replace(state: TalkStreamState, page: TalkPage, window: TalkWindow): TalkStreamState {
    const messages = page.messages.filter((message) => !state.deleted.has(message.id)).sort(byTuple);

    return { messages, hasOlder: page.hasOlder, deleted: state.deleted, window, generation: state.generation + 1 };
}

/**
 * The page the server rendered with. It is the newest one for an ordinary visit, but a deep link
 * (`?m=`) opens on the page a message sits in — so the window is read off `hasNewer` exactly as a
 * forward read's is, rather than assumed live. Standing in a history window from the first render is
 * what stops the poll appending rows that do not follow the last one on screen.
 */
export function initial(page: TalkPage): TalkStreamState {
    const empty: TalkStreamState = { messages: [], hasOlder: false, deleted: new Set(), window: { kind: 'latest' }, generation: 0 };

    return merge(empty, page.messages, page.hasOlder, windowAfter(page));
}

/**
 * The poll's answer to "what has arrived since my newest message". It reads forward only, so it
 * learns nothing about the far end of the history and leaves `hasOlder` alone.
 */
export function mergeAfter(state: TalkStreamState, page: TalkPage): TalkStreamState {
    return merge(state, page.messages, state.hasOlder);
}

/**
 * The poll's answer when there was no watermark to ask after — the conversation was empty when the
 * page loaded, so it asked for the newest page instead. That page knows what lies behind it, and
 * adopting its answer is what keeps a busy group's history reachable without a reload. Once the list
 * is non-empty the current answer is the better one: this response only covers the newest page.
 */
export function mergeLatest(state: TalkStreamState, page: TalkPage): TalkStreamState {
    return merge(state, page.messages, state.messages.length === 0 ? page.hasOlder : state.hasOlder);
}

/** "Load older": the response is the authority on whether anything remains behind it. */
export function mergeBefore(state: TalkStreamState, page: TalkPage): TalkStreamState {
    return merge(state, page.messages, page.hasOlder);
}

/**
 * The composer's own message, echoed back by the write.
 *
 * Held to the live window rather than to a generation: the message belongs at the foot of *any* list
 * that ends at the newest one, including the one the caller re-read to get back there, but under a
 * stretch of history it would sit beneath a message it does not answer.
 */
export function mergeSent(state: TalkStreamState, message: TalkMessage): TalkStreamState {
    if (state.window.kind !== 'latest') {
        return state;
    }

    return merge(state, [message], state.hasOlder);
}

/**
 * "Load newer" in the history window: the page after the last row on screen, which is contiguous
 * with it and so may be merged. When the server reports nothing beyond it, the list now runs to the
 * newest message and the window is the latest one again — poll and all.
 */
export function mergeNewer(state: TalkStreamState, page: TalkPage): TalkStreamState {
    const window = windowAfter(page);
    // Catching up with the newest message is a window change like any other, so it retires the reads
    // the history window had out and lets the poll start from a list it can trust.
    const generation = window.kind === 'latest' ? state.generation + 1 : state.generation;

    return merge(state, page.messages, state.hasOlder, window, generation);
}

/** The unread jump: stand on the boundary page. Contiguous through to the newest ends up as latest. */
export function enterHistory(state: TalkStreamState, page: TalkPage): TalkStreamState {
    return replace(state, page, windowAfter(page));
}

/** Back to the live end of the conversation, on the newest page rather than on what was held. */
export function enterLatest(state: TalkStreamState, page: TalkPage): TalkStreamState {
    return replace(state, page, { kind: 'latest' });
}

/**
 * Drop a message and remember that it is gone. Not tied to a generation either: a deletion is a fact
 * about the conversation rather than a page of it, and holds wherever the reader is standing.
 */
export function markDeleted(state: TalkStreamState, id: number): TalkStreamState {
    const deleted = new Set(state.deleted);
    deleted.add(id);

    return {
        messages: state.messages.filter((message) => message.id !== id),
        hasOlder: state.hasOlder,
        deleted,
        window: state.window,
        generation: state.generation,
    };
}

/** The newest message's cursor — what the poll asks after. Undefined while the list is empty. */
export function watermark(state: TalkStreamState): string | undefined {
    return state.messages[state.messages.length - 1]?.cursor;
}

/** The oldest message's cursor — what "load older" asks before. Undefined while the list is empty. */
export function oldestBoundary(state: TalkStreamState): string | undefined {
    return state.messages[0]?.cursor;
}
