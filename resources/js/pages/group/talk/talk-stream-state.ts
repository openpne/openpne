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
export interface TalkStreamState {
    /** Ascending by (createdAt, id). */
    messages: TalkMessage[];
    hasOlder: boolean;
    /** Ids deleted in this session. Never re-admitted, whatever a later response carries. */
    deleted: ReadonlySet<number>;
}

/** Sort key: the instant, with the id breaking a tie inside the same second. */
function orderOf(message: TalkMessage): [number, number] {
    const at = Date.parse(message.createdAt);

    // An unparseable stamp sorts first rather than poisoning the comparison — NaN compares false
    // against everything, which would make the ordering intransitive and the sort result arbitrary.
    return [Number.isNaN(at) ? 0 : at, message.id];
}

function byTuple(a: TalkMessage, b: TalkMessage): number {
    const [aAt, aId] = orderOf(a);
    const [bAt, bId] = orderOf(b);

    return aAt === bAt ? aId - bId : aAt - bAt;
}

/**
 * Fold messages into the list: later copies of an id win, tombstoned ids are dropped, and the result
 * is sorted. `hasOlder` is decided by the caller, since only it knows which end it read from.
 */
function merge(state: TalkStreamState, arriving: readonly TalkMessage[], hasOlder: boolean): TalkStreamState {
    const held = new Map(state.messages.map((message) => [message.id, message]));
    for (const message of arriving) {
        held.set(message.id, message);
    }

    const messages = [...held.values()].filter((message) => !state.deleted.has(message.id)).sort(byTuple);

    return { messages, hasOlder, deleted: state.deleted };
}

export function initial(page: TalkPage): TalkStreamState {
    return merge({ messages: [], hasOlder: false, deleted: new Set() }, page.messages, page.hasOlder);
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

/** The composer's own message, echoed back by the write. */
export function mergeSent(state: TalkStreamState, message: TalkMessage): TalkStreamState {
    return merge(state, [message], state.hasOlder);
}

/** Drop a message and remember that it is gone. */
export function markDeleted(state: TalkStreamState, id: number): TalkStreamState {
    const deleted = new Set(state.deleted);
    deleted.add(id);

    return {
        messages: state.messages.filter((message) => message.id !== id),
        hasOlder: state.hasOlder,
        deleted,
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
