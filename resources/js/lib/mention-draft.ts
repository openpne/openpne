import { codePointLength, utf16ToCodePoint } from './code-points.ts';

/**
 * The compose form's @mention draft: what the picker inserted, carried through every edit that
 * follows so the payload submitted still describes the body submitted with it.
 *
 * A draft entry holds its start as a **UTF-16** offset, the unit the DOM reports carets in, and is
 * converted to code points once — at {@link toPayload}, on the value actually being sent. Tracking
 * code points through each keystroke would mean converting on every edit instead.
 *
 * All of this is pure: the state machine is the part worth pinning by test, and the component around
 * it (mention-textarea.tsx) only feeds it the caret, the value and the picked candidate.
 */

/** A mention the picker inserted, positioned by the UTF-16 offset of its `@`. */
export interface DraftMention {
    memberId: number;
    /** The display name as it was inserted, without the leading `@`. */
    label: string;
    start: number;
}

/** What the picker needs of a candidate to insert it. */
export interface MentionCandidate {
    id: number;
    name: string;
}

/** The `@query` the caret sits in, as UTF-16 offsets: `start` is the `@`, `end` is the caret. */
export interface MentionTrigger {
    start: number;
    end: number;
    query: string;
}

/** One row of the `mentions[]` payload (App\Http\Requests\Concerns\MentionRules), in code points. */
export interface MentionPayloadRow {
    member_id: number;
    offset: number;
    length: number;
}

/** Rows a post may carry; past this the picker stops offering (`mentions` max:10). */
export const MAX_MENTIONS = 10;

/** Past this a search term is no longer a name being typed, so the trigger ends rather than widens. */
const MAX_QUERY = 20;

// A handle is a word: it starts the body or follows a space. `\s` covers the ideographic space a
// Japanese keyboard produces, and the newline that keeps a trigger inside its own line.
const SPACE = /\s/u;

/**
 * The trigger the caret is inside, or null. Scans back from the caret for the `@` that opens it, so
 * `a@b` (an address), a second `@` in `@@a`, and anything past a space or line break are not one.
 */
export function detectTrigger(value: string, caret: number): MentionTrigger | null {
    for (let i = caret - 1; i >= 0; i -= 1) {
        const char = value.charAt(i);
        if (SPACE.test(char)) {
            return null;
        }
        if (char !== '@') {
            continue;
        }
        if (i > 0 && !SPACE.test(value.charAt(i - 1))) {
            return null;
        }

        const query = value.slice(i + 1, caret);

        return codePointLength(query) > MAX_QUERY ? null : { start: i, end: caret, query };
    }

    return null;
}

/** A search result, tagged with the query it answers. */
export interface MentionResults<C> {
    query: string;
    items: C[];
}

/**
 * The candidates the picker may show and confirm: only ones searched for the query the caret is in
 * right now. Typing on past `@a` leaves that search's answer in hand until the next one lands, and
 * confirming it would attach a member who was never offered for what the field now reads.
 */
export function offeredCandidates<C>(query: string | null, results: MentionResults<C> | null): C[] {
    return query !== null && results !== null && results.query === query ? results.items : [];
}

/** What an open picker does with a keystroke; null leaves the key to the field. */
export type MentionKey = 'next' | 'previous' | 'confirm' | 'dismiss' | null;

/**
 * Which keys the picker takes. Only an open list takes any — Enter is a line break the rest of the
 * time — and a converting IME takes none at all: the Enter that commits a conversion would otherwise
 * be read as a pick, and the arrows walk the IME's own candidates.
 */
export function keyAction(key: string, state: { open: boolean; composing: boolean }): MentionKey {
    if (state.composing || !state.open) {
        return null;
    }
    switch (key) {
        case 'ArrowDown':
            return 'next';
        case 'ArrowUp':
            return 'previous';
        case 'Enter':
        case 'Tab':
            return 'confirm';
        case 'Escape':
            return 'dismiss';
        default:
            return null;
    }
}

export interface PickResult {
    value: string;
    mentions: DraftMention[];
    /** Where the caret belongs afterwards: past the space that closed the handle. */
    caret: number;
}

/** Replace the trigger with the candidate's handle, and record the mention it just became. */
export function applyPick(mentions: DraftMention[], value: string, trigger: MentionTrigger, candidate: MentionCandidate): PickResult {
    // The trailing space ends the trigger the caret is still in — without it the next keystroke
    // would reopen the picker on the name just chosen.
    const handle = `@${candidate.name} `;

    return {
        value: value.slice(0, trigger.start) + handle + value.slice(trigger.end),
        mentions: [
            ...carry(mentions, trigger.start, trigger.end, handle.length),
            { memberId: candidate.id, label: candidate.name, start: trigger.start },
        ],
        caret: trigger.start + handle.length,
    };
}

/**
 * Carry the draft across an edit, given the value before and after it.
 *
 * A pair of values does not name the span that changed: where the text repeats around the edit,
 * deleting the first `@Alice ` of two and deleting the second leave the very same pair behind. So
 * the edit is read twice, from each end, and a mention is carried only where both readings say the
 * same thing — see {@link settle} for what is done with the rest.
 */
export function applyEdit(mentions: DraftMention[], oldValue: string, newValue: string): DraftMention[] {
    if (mentions.length === 0) {
        return mentions;
    }

    // What the two values share at either end. A textarea edit is a single contiguous change
    // (typing, deleting, pasting over a selection), so one span describes it — and where a scan
    // splits a surrogate pair, both halves sit inside one code point, which no mention boundary ever
    // falls inside.
    const limit = Math.min(oldValue.length, newValue.length);
    let prefix = 0;
    while (prefix < limit && oldValue.charCodeAt(prefix) === newValue.charCodeAt(prefix)) {
        prefix += 1;
    }
    let suffix = 0;
    while (suffix < limit && oldValue.charCodeAt(oldValue.length - 1 - suffix) === newValue.charCodeAt(newValue.length - 1 - suffix)) {
        suffix += 1;
    }

    // The two ends the shared runs may be split at: the rightmost span the prefix allows, and the
    // leftmost one the suffix allows. They coincide unless the runs overlap, which is exactly when
    // the edit is ambiguous.
    const left = read(mentions, oldValue, newValue, prefix, Math.min(suffix, limit - prefix));
    const right = read(mentions, oldValue, newValue, Math.min(prefix, limit - suffix), suffix);

    return mentions.flatMap((mention, index) => {
        const start = settle(left[index] ?? null, right[index] ?? null, mention, mentions);

        return start === null ? [] : [{ ...mention, start }];
    });
}

/** Where each mention lands under one reading of the edit. */
function read(mentions: DraftMention[], oldValue: string, newValue: string, start: number, suffix: number): (number | null)[] {
    return mentions.map((mention) => carryOne(mention, start, oldValue.length - suffix, newValue.length - suffix - start));
}

/**
 * The start both readings support, or null to give the mention up.
 *
 * Where they disagree the draft may not guess: picking the wrong one of two same-named members
 * points the link and the notification at the member the writer just deleted, and nothing
 * downstream can catch it — {@link toPayload} and the server both re-read the same `@name` text.
 * Losing a mention to plain text is the honest failure.
 *
 * A mention only one reading keeps is still safe to keep while no other entry carries its label:
 * the text it covers can then belong to no one else, whichever reading was true.
 */
function settle(left: number | null, right: number | null, mention: DraftMention, mentions: DraftMention[]): number | null {
    if (left === right) {
        return left;
    }
    if (left !== null && right !== null) {
        return null;
    }

    return mentions.some((other) => other !== mention && other.label === mention.label) ? null : (left ?? right);
}

/**
 * Where a mention lands once `[start, end)` has become `inserted` code units, or null if the edit
 * reached into it — only text the member picked may stay a mention, and they just rewrote part of
 * it. A mention before the edit is untouched, one after it moves.
 */
function carryOne(mention: DraftMention, start: number, end: number, inserted: number): number | null {
    if (mention.start + 1 + mention.label.length <= start) {
        return mention.start;
    }
    if (mention.start >= end) {
        return mention.start + inserted - (end - start);
    }

    return null;
}

/** The draft after `[start, end)` became `inserted` code units. */
function carry(mentions: DraftMention[], start: number, end: number, inserted: number): DraftMention[] {
    return mentions.flatMap((mention) => {
        const carried = carryOne(mention, start, end, inserted);

        return carried === null ? [] : [{ ...mention, start: carried }];
    });
}

/**
 * The rows to submit with `value`, ascending by offset. Each is re-read off the body first: a draft
 * entry survives only while the text it covers still reads as the handle the picker wrote, the same
 * shape the server re-checks against the member's current name (ResolveMentions).
 */
export function toPayload(mentions: DraftMention[], value: string): MentionPayloadRow[] {
    return mentions
        .filter((mention) => value.slice(mention.start, mention.start + 1 + mention.label.length) === `@${mention.label}`)
        .map((mention) => ({
            member_id: mention.memberId,
            offset: utf16ToCodePoint(value, mention.start),
            length: 1 + codePointLength(mention.label),
        }))
        .sort((a, b) => a.offset - b.offset);
}
