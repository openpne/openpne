import { useCallback, useEffect, useRef, useState } from 'react';
import { xsrfHeader } from '@/lib/csrf';
import type { MentionPayloadRow } from '@/lib/mention-draft';
import {
    initial,
    markDeleted,
    mergeAfter,
    mergeBefore,
    mergeLatest,
    mergeSent,
    oldestBoundary,
    type TalkStreamState,
    watermark,
} from './talk-stream-state';
import type { TalkMessage, TalkPage } from './types';

/** How often a visible tab asks what has arrived. */
const POLL_MS = 8_000;

/** Thrown for a rejected send, carrying whatever the server said about each field. */
export class SendFailed extends Error {
    /** @param errors first validation message per field (`body`, `image`, …); empty for any other refusal. */
    constructor(public readonly errors: Record<string, string>) {
        super('send failed');
    }
}

/**
 * Holds the conversation a talk page is showing and keeps it current: a visibility-aware poll for
 * what has arrived (the pattern the unread badges already use — Inertia's usePoll keeps firing on a
 * hidden tab and does not refresh on return, which is the moment a stale chat is seen), plus "load
 * older" walking back by keyset and the composer's send appending in place.
 *
 * This hook owns the network and nothing else: what arrives is folded in by the pure merges in
 * talk-stream-state.ts, which is where the ordering, dedupe and tombstone rules live.
 */
export function useTalkStream(groupId: number, page: TalkPage) {
    const [state, setState] = useState<TalkStreamState>(() => initial(page));
    const [loadingOlder, setLoadingOlder] = useState(false);

    // What the interval reads. Reading state there would capture the value the tick was created with.
    const stateRef = useRef(state);
    useEffect(() => {
        stateRef.current = state;
    }, [state]);

    const fetchPage = useCallback(
        async (query: string, signal?: AbortSignal): Promise<TalkPage | null> => {
            const response = await fetch(`/groups/${groupId}/talk/messages${query}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal,
            });

            return response.ok ? ((await response.json()) as TalkPage) : null;
        },
        [groupId],
    );

    useEffect(() => {
        let inFlight: AbortController | null = null;

        const poll = () => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            inFlight?.abort();
            const controller = new AbortController();
            inFlight = controller;

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
                    setState((current) =>
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
            inFlight?.abort();
        };
    }, [fetchPage]);

    const loadOlder = useCallback(async () => {
        const boundary = oldestBoundary(stateRef.current);
        if (boundary === undefined || loadingOlder) {
            return;
        }

        setLoadingOlder(true);
        try {
            const older = await fetchPage(`?before=${encodeURIComponent(boundary)}`);
            if (older !== null) {
                setState((current) => mergeBefore(current, older));
            }
        } finally {
            setLoadingOlder(false);
        }
    }, [fetchPage, loadingOlder]);

    const send = useCallback(
        async (body: string, mentions: MentionPayloadRow[] = [], image: File | null = null) => {
            // Multipart throughout, not only when a file rides along: one transport is one set of
            // encoding rules to reason about. It costs the body its LF newlines — FormData encodes
            // them as CRLF — which is exactly why StoreGroupMessageRequest re-normalizes before it
            // measures anything, so the mention offsets computed over the textarea's LF value still
            // describe the body that is stored.
            const form = new FormData();
            form.append('body', body);
            mentions.forEach((mention, index) => {
                form.append(`mentions[${index}][member_id]`, String(mention.member_id));
                form.append(`mentions[${index}][offset]`, String(mention.offset));
                form.append(`mentions[${index}][length]`, String(mention.length));
            });
            if (image !== null) {
                form.append('image', image);
            }

            const response = await fetch(`/groups/${groupId}/talk`, {
                method: 'POST',
                // No Content-Type: the browser sets it with the multipart boundary.
                headers: { ...xsrfHeader(), Accept: 'application/json' },
                credentials: 'same-origin',
                body: form,
            });

            if (!response.ok) {
                throw new SendFailed(await errorsOf(response));
            }

            const message = (await response.json()) as TalkMessage;
            setState((current) => mergeSent(current, message));
        },
        [groupId],
    );

    const remove = useCallback(
        async (id: number) => {
            const response = await fetch(`/groups/${groupId}/talk/messages/${id}/delete`, {
                method: 'POST',
                headers: { ...xsrfHeader(), Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                return;
            }

            setState((current) => markDeleted(current, id));
        },
        [groupId],
    );

    return { messages: state.messages, hasOlder: state.hasOlder, loadingOlder, loadOlder, send, remove };
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
