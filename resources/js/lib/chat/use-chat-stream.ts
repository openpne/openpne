import { useCallback, useEffect, useRef, useState } from 'react';
import { xsrfHeader } from '@/lib/csrf';
import { consumeHistoryRestore } from '@/lib/history-restore';
import {
    applied,
    applyReaction,
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
    mergeTouched,
    newIntents,
    oldestBoundary,
    retireIntents,
    watermark,
} from './stream-state';
import type { ReactionOp } from './reaction-overlay';
import type { ChatPage, ChatStreamRow } from './types';

const POLL_MS = 8_000;

/**
 * `messages` is handed the keyset query, empty for the newest page. The write endpoints are
 * optional because a conversation may be read-only, and `send` and `remove` throw rather than post
 * to a URL the page never declared.
 */
export interface ChatStreamEndpoints {
    messages: (query: string) => string;
    send?: string;
    delete?: (id: number) => string;
}

/** Without it the poll asks what it always asked and `react` refuses. */
export interface ChatReactions {
    /** The conversation's reaction version at render — where the poll starts reading changes from. */
    initialVersion: number;
    add: (messageId: number) => string;
    remove: (messageId: number) => string;
}

export class SendFailed extends Error {
    /** @param errors first validation message per field (`body`, `images`, `images.N`, …); empty for any other refusal. */
    constructor(public readonly errors: Record<string, string>) {
        super('send failed');
    }
}

/**
 * A visibility-aware poll rather than Inertia's usePoll, which keeps firing on a hidden tab and does
 * not refresh on return — the moment a stale chat is seen. This hook owns the network and nothing
 * else: what arrives is folded in by the pure merges in stream-state.ts.
 */
export function useChatStream<M extends ChatStreamRow>(endpoints: ChatStreamEndpoints, page: ChatPage<M>, reactions?: ChatReactions) {
    const [state, setState] = useState<ChatStreamState<M>>(() => initial(page, reactions?.initialVersion));
    const [loadingOlder, setLoadingOlder] = useState(false);
    const [loadingNewer, setLoadingNewer] = useState(false);

    // Reading state in the interval would capture the value the tick was created with.
    const stateRef = useRef(state);
    useEffect(() => {
        stateRef.current = state;
    }, [state]);

    // The poll's read, held where a window change can reach it: moving the page somewhere else makes
    // the answer already on the wire worthless, and there is no reason to wait for it.
    const polling = useRef<AbortController | null>(null);
    // The last intent wins — see ChatIntents.
    const intents = useRef(newIntents());
    const navigating = useRef<AbortController | null>(null);

    const claimMove = useCallback((): number => {
        navigating.current?.abort();
        // Standing somewhere else makes the poll's answer worthless too.
        polling.current?.abort();

        return claimIntent(intents.current);
    }, []);

    /** The one door every response comes through: `at` is the generation the read was issued against. */
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
            // Only the window that ends at the newest message has anything to poll for; in the
            // history window what arrives does not follow the last row on screen.
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
            const asked: string[] = [];
            if (since !== undefined) {
                asked.push(`after=${encodeURIComponent(since)}`);
            }
            // Reading forward from a cursor cannot see a reaction, which moves neither half of the
            // ordering tuple.
            const sinceReactions = stateRef.current.reactionsVersion;
            if (sinceReactions !== undefined) {
                asked.push(`reactionsAfter=${sinceReactions}`);
            }
            const query = asked.length === 0 ? '' : `?${asked.join('&')}`;

            void fetchPage(query, controller.signal)
                .then((arrived) => {
                    if (arrived === null) {
                        return;
                    }
                    // The touched rows and the watermark ride the same fold, so a discarded response
                    // moves neither: a watermark moved alone would mark changes as read into a list
                    // that never saw them.
                    fold(at, (current) => {
                        const merged = since === undefined ? mergeLatest(current, arrived) : mergeAfter(current, arrived);

                        return mergeTouched(merged, arrived.touched ?? [], arrived.reactionsVersion);
                    });
                })
                .catch(() => {
                    // Keep what is on screen: a dropped refresh is not news to the reader, and the
                    // next tick or a real navigation answers an expired session.
                });
        };

        const timer = setInterval(poll, POLL_MS);
        // Returning to the tab is when a stale conversation is seen, so refresh then too.
        document.addEventListener('visibilitychange', poll);
        // This list is seeded once at mount, so the app-wide reload that answers a restore does not
        // refresh it and the restore has to poll here.
        if (consumeHistoryRestore()) {
            poll();
        }

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
     * The cursor is one the server handed over, echoed back as `before` and `after` are. What comes
     * back replaces the list rather than joining it.
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
            if (endpoints.send === undefined) {
                throw new Error('this conversation is read-only: no send endpoint was declared');
            }

            // Multipart throughout, not only when a file rides along: FormData encodes the body's LF
            // newlines as CRLF, which is why the server re-normalizes before it measures anything.
            const form = new FormData();
            form.append('body', body);
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
            // waiting for is retired here.
            retireIntents(intents.current);
            navigating.current?.abort();

            // When the re-read of the newest page fails, the list is emptied down to your own
            // message with everything else behind "load older".
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
            if (endpoints.delete === undefined) {
                throw new Error('this conversation is read-only: no delete endpoint was declared');
            }

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

    /**
     * The row the write answers with is read for nothing else: a slow answer landing after the poll
     * has moved the watermark past it would put back a count no later poll corrects. Not held to a
     * generation, for the reason a deletion is not: a chip row is a fact about the message rather
     * than the page.
     */
    const react = useCallback(
        async (id: number, emoji: string, op: ReactionOp): Promise<boolean> => {
            if (reactions === undefined) {
                throw new Error('this conversation has no reactions: no endpoints were declared');
            }

            const versionAtSend = stateRef.current.reactionsVersion;

            const response = await fetch(op === 'add' ? reactions.add(id) : reactions.remove(id), {
                method: 'POST',
                headers: { ...xsrfHeader(), Accept: 'application/json', 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ emoji }),
            }).catch(() => null);

            if (response === null || !response.ok) {
                // A refusal says nothing to the reader: a narrowed vocabulary (422) and a message
                // deleted from under the tap (404) are both answered by the guess going away.
                return false;
            }

            setState((current) => applyReaction(current, id, emoji, op, versionAtSend));

            return true;
        },
        [reactions],
    );

    return {
        messages: state.messages,
        hasOlder: state.hasOlder,
        window: state.window,
        // It moves only when a window change lands, so a caller can tell its own jump's render from
        // the polls and merges around it.
        generation: state.generation,
        loadingOlder,
        loadingNewer,
        loadOlder,
        loadNewer,
        openContext,
        returnToLatest,
        send,
        remove,
        react,
    };
}

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
