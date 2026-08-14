import { useCallback, useEffect, useRef, useState } from 'react';
import { xsrfHeader } from '@/lib/csrf';
import {
    applied,
    type ChatStreamState,
    claimIntent,
    enterHistory,
    enterLatest,
    initial,
    isCurrentIntent,
    markDeleted,
    mergeAfter,
    mergeBefore,
    mergeLatest,
    mergeNewer,
    mergeSent,
    newIntents,
    oldestBoundary,
    retireIntents,
    watermark,
} from './stream-state';
import type { ChatPage, ChatStreamRow } from './types';

/** How often a visible tab asks what has arrived. */
const POLL_MS = 8_000;

/** Where one conversation lives. `messages` is handed the keyset query, empty for the newest page. */
export interface ChatStreamEndpoints {
    messages: (query: string) => string;
    send: string;
    delete: (id: number) => string;
}

/** Thrown for a rejected send, carrying whatever the server said about each field. */
export class SendFailed extends Error {
    /** @param errors first validation message per field (`body`, `images`, `images.N`, …); empty for any other refusal. */
    constructor(public readonly errors: Record<string, string>) {
        super('send failed');
    }
}

/**
 * Holds the conversation a chat page is showing and keeps it current: a visibility-aware poll for
 * what has arrived (the pattern the unread badges already use — Inertia's usePoll keeps firing on a
 * hidden tab and does not refresh on return, which is the moment a stale chat is seen), plus "load
 * older" walking back by keyset and the composer's send appending in place.
 *
 * This hook owns the network and nothing else: what arrives is folded in by the pure merges in
 * stream-state.ts, which is where the ordering, dedupe, tombstone and window rules live. The
 * window is why the poll is conditional — reading back from the unread boundary opens a stretch that
 * does not end at the newest message, and the reader steps forward through it instead.
 */
export function useChatStream<M extends ChatStreamRow>(endpoints: ChatStreamEndpoints, page: ChatPage<M>) {
    const [state, setState] = useState<ChatStreamState<M>>(() => initial(page));
    const [loadingOlder, setLoadingOlder] = useState(false);
    const [loadingNewer, setLoadingNewer] = useState(false);

    // What the interval reads. Reading state there would capture the value the tick was created with.
    const stateRef = useRef(state);
    useEffect(() => {
        stateRef.current = state;
    }, [state]);

    // The poll's read, held where a window change can reach it: moving the page somewhere else makes
    // the answer already on the wire worthless, and there is no reason to wait for it.
    const polling = useRef<AbortController | null>(null);
    // Which move to another stretch of the conversation the reader is waiting for, and the read
    // fetching it. The last intent wins — see ChatIntents.
    const intents = useRef(newIntents());
    const navigating = useRef<AbortController | null>(null);

    /** Claim the newest move, dropping the read any earlier one still has out. */
    const claimMove = useCallback((): number => {
        navigating.current?.abort();
        // Standing somewhere else makes the poll's answer worthless too.
        polling.current?.abort();

        return claimIntent(intents.current);
    }, []);

    /**
     * The one door every response comes through. `at` is the generation the read was issued against;
     * `applied` drops it when the list has since moved somewhere else, which is what keeps a poll
     * that outlived the live window from being spliced into a slice of history.
     */
    const fold = useCallback((at: number, update: (current: ChatStreamState<M>) => ChatStreamState<M>) => {
        setState((current) => applied(current, at, update));
    }, []);

    const fetchPage = useCallback(
        async (query: string, signal?: AbortSignal): Promise<ChatPage<M> | null> => {
            const response = await fetch(endpoints.messages(query), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal,
            });

            return response.ok ? ((await response.json()) as ChatPage<M>) : null;
        },
        [endpoints],
    );

    useEffect(() => {
        const poll = () => {
            // Only the window that ends at the newest message has anything to poll for: in the
            // history window what arrives does not follow the last row on screen, and folding it in
            // would put a hole in the middle of the conversation.
            if (document.visibilityState !== 'visible' || stateRef.current.window.kind !== 'latest') {
                return;
            }

            polling.current?.abort();
            const controller = new AbortController();
            polling.current = controller;

            const at = stateRef.current.generation;
            const since = watermark(stateRef.current);
            // Nothing on screen means there is no position to ask after — the conversation was empty
            // when the page loaded, so ask for the newest page instead.
            const query = since === undefined ? '' : `?after=${encodeURIComponent(since)}`;

            void fetchPage(query, controller.signal)
                .then((arrived) => {
                    if (arrived === null) {
                        return;
                    }
                    // Which merge is decided by which question was asked, not by what came back.
                    fold(at, (current) =>
                        since === undefined ? mergeLatest(current, arrived) : mergeAfter(current, arrived),
                    );
                })
                .catch(() => {
                    // Keep what is on screen: a dropped refresh is not news to the reader, and the
                    // next tick or a real navigation answers an expired session.
                });
        };

        const timer = setInterval(poll, POLL_MS);
        // Returning to the tab is when a stale conversation is seen, so refresh then too.
        document.addEventListener('visibilitychange', poll);

        return () => {
            clearInterval(timer);
            document.removeEventListener('visibilitychange', poll);
            polling.current?.abort();
        };
    }, [fetchPage, fold]);

    const loadOlder = useCallback(async () => {
        const boundary = oldestBoundary(stateRef.current);
        if (boundary === undefined || loadingOlder) {
            return;
        }

        const at = stateRef.current.generation;
        setLoadingOlder(true);
        try {
            const older = await fetchPage(`?before=${encodeURIComponent(boundary)}`).catch(() => null);
            if (older !== null) {
                fold(at, (current) => mergeBefore(current, older));
            }
        } finally {
            setLoadingOlder(false);
        }
    }, [fetchPage, fold, loadingOlder]);

    /**
     * Open on the page a position sits in — the unread boundary today, a linked-to message next.
     * The cursor is one the server handed over (the unread snapshot's, a message's own), echoed back
     * as `before` and `after` are. What comes back is a stretch of history, and it replaces the list
     * rather than joining it.
     */
    const openContext = useCallback(
        async (cursor: string): Promise<boolean> => {
            const epoch = claimMove();
            const controller = new AbortController();
            navigating.current = controller;

            const at = stateRef.current.generation;
            // A refusal, a dropped connection and an abort all mean the same thing to the caller: the
            // move did not happen, so it can put back whatever it was holding for it.
            const around = await fetchPage(`?context=${encodeURIComponent(cursor)}`, controller.signal).catch(() => null);
            if (around === null || !isCurrentIntent(intents.current, epoch)) {
                return false;
            }
            fold(at, (current) => enterHistory(current, around));

            return true;
        },
        [claimMove, fetchPage, fold],
    );

    /** "Load newer": one page forward from the foot of the history window. */
    const loadNewer = useCallback(async () => {
        const boundary = watermark(stateRef.current);
        if (boundary === undefined || loadingNewer) {
            return;
        }

        const at = stateRef.current.generation;
        setLoadingNewer(true);
        try {
            const newer = await fetchPage(`?after=${encodeURIComponent(boundary)}`).catch(() => null);
            if (newer !== null) {
                fold(at, (current) => mergeNewer(current, newer));
            }
        } finally {
            setLoadingNewer(false);
        }
    }, [fetchPage, fold, loadingNewer]);

    /** Back to the live end: the newest page, replacing whatever stretch was being read. */
    const returnToLatest = useCallback(async (): Promise<boolean> => {
        const epoch = claimMove();
        const controller = new AbortController();
        navigating.current = controller;

        const at = stateRef.current.generation;
        const latest = await fetchPage('', controller.signal).catch(() => null);
        if (latest === null || !isCurrentIntent(intents.current, epoch)) {
            return false;
        }
        fold(at, (current) => enterLatest(current, latest));

        return true;
    }, [claimMove, fetchPage, fold]);

    const send = useCallback(
        async (body: string, images: File[] = [], appendFields?: (form: FormData) => void) => {
            // Multipart throughout, not only when a file rides along: one transport is one set of
            // encoding rules to reason about. It costs the body its LF newlines — FormData encodes
            // them as CRLF — which is exactly why the server re-normalizes before it measures
            // anything, so the mention offsets computed over the textarea's LF value still describe
            // the body that is stored.
            const form = new FormData();
            form.append('body', body);
            // Whatever else this conversation sends with a message — talk's mention ranges, say.
            appendFields?.(form);
            // Appended in pick order: the slot numbers the server writes are that order.
            images.forEach((image) => form.append('images[]', image));

            const response = await fetch(endpoints.send, {
                method: 'POST',
                // No Content-Type: the browser sets it with the multipart boundary.
                headers: { ...xsrfHeader(), Accept: 'application/json' },
                credentials: 'same-origin',
                body: form,
            });

            if (!response.ok) {
                throw new SendFailed(await errorsOf(response));
            }

            const message = (await response.json()) as M;

            // Writing is the newest intent, so a jump the reader asked for and then wrote instead of
            // waiting for is retired here — its page would otherwise arrive and replace the list out
            // from under the message just written, which is the one thing the rule below promises
            // cannot happen.
            retireIntents(intents.current);
            navigating.current?.abort();

            // Writing puts you back at the live end. Appending it to a history window would sit your
            // words directly under a message they do not answer, so the newest page is re-read
            // instead — and when that read fails, the list is emptied down to your own message with
            // everything else behind "load older", which is a conversation rather than a gap drawn
            // as one.
            //
            // The message itself is not held to a generation, because it belongs at the foot of any
            // live list including the one just re-read to get back there; `mergeSent` refuses a
            // history window instead, which is the condition that actually matters and is answered
            // against the state at the moment it lands.
            if (stateRef.current.window.kind === 'history' && !(await returnToLatest())) {
                const at = stateRef.current.generation;
                fold(at, (current) => enterLatest(current, { messages: [], hasOlder: true, hasNewer: false }));
            }

            setState((current) => mergeSent(current, message));
        },
        [endpoints, fold, returnToLatest],
    );

    const remove = useCallback(
        async (id: number) => {
            const response = await fetch(endpoints.delete(id), {
                method: 'POST',
                headers: { ...xsrfHeader(), Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                return;
            }

            setState((current) => markDeleted(current, id));
        },
        [endpoints],
    );

    return {
        messages: state.messages,
        hasOlder: state.hasOlder,
        window: state.window,
        // Which list the page is standing on. It moves only when a window change lands, so a caller
        // can tell its own jump's render from the polls and merges around it.
        generation: state.generation,
        loadingOlder,
        loadingNewer,
        loadOlder,
        loadNewer,
        openContext,
        returnToLatest,
        send,
        remove,
    };
}

/** The first validation message per field from a 422; empty for any other refusal. */
async function errorsOf(response: Response): Promise<Record<string, string>> {
    if (response.status !== 422) {
        return {};
    }

    try {
        const payload = (await response.json()) as { errors?: Record<string, string[]> };
        const errors: Record<string, string> = {};
        for (const [field, messages] of Object.entries(payload.errors ?? {})) {
            const first = messages[0];
            if (first !== undefined) {
                errors[field] = first;
            }
        }

        return errors;
    } catch {
        return {};
    }
}
