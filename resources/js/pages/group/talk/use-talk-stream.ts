import { useCallback, useEffect, useRef, useState } from 'react';
import { xsrfHeader } from '@/lib/csrf';
import type { TalkMessage, TalkPage } from './types';

/** How often a visible tab asks what has arrived. */
const POLL_MS = 8_000;

/** Thrown for a rejected send, carrying what the server said about the body. */
export class SendFailed extends Error {
    constructor(public readonly bodyError: string | null) {
        super('send failed');
    }
}

/**
 * Holds the conversation a talk page is showing and keeps it current: a visibility-aware poll for
 * what has arrived (the pattern the unread badges already use — Inertia's usePoll keeps firing on a
 * hidden tab and does not refresh on return, which is the moment a stale chat is seen), plus "load
 * older" walking back by keyset and the composer's send appending in place.
 *
 * Merging is by id, so a message can never be listed twice — a send and the poll that overlaps it
 * both produce the same row by design.
 */
export function useTalkStream(groupId: number, initial: TalkPage) {
    const [page, setPage] = useState<TalkPage>(initial);
    const [loadingOlder, setLoadingOlder] = useState(false);

    // What the interval reads. Reading state there would capture the page the tick was created with.
    const pageRef = useRef(page);
    useEffect(() => {
        pageRef.current = page;
    }, [page]);

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

    const append = useCallback((arriving: TalkMessage[]) => {
        if (arriving.length === 0) {
            return;
        }

        setPage((current) => {
            const known = new Set(current.messages.map((message) => message.id));
            const fresh = arriving.filter((message) => !known.has(message.id));

            return fresh.length === 0 ? current : { ...current, messages: [...current.messages, ...fresh] };
        });
    }, []);

    useEffect(() => {
        let inFlight: AbortController | null = null;

        const poll = () => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            inFlight?.abort();
            const controller = new AbortController();
            inFlight = controller;

            const messages = pageRef.current.messages;
            const watermark = messages[messages.length - 1]?.cursor;
            // Nothing on screen means there is no position to ask after — the conversation was empty
            // when the page loaded, so ask for the newest page instead.
            const query = watermark === undefined ? '' : `?after=${encodeURIComponent(watermark)}`;

            void fetchPage(query, controller.signal)
                .then((arrived) => arrived !== null && append(arrived.messages))
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
    }, [append, fetchPage]);

    const loadOlder = useCallback(async () => {
        const boundary = pageRef.current.messages[0]?.cursor;
        if (boundary === undefined || loadingOlder) {
            return;
        }

        setLoadingOlder(true);
        try {
            const older = await fetchPage(`?before=${encodeURIComponent(boundary)}`);
            if (older === null) {
                return;
            }

            setPage((current) => ({
                messages: [...older.messages, ...current.messages],
                hasOlder: older.hasOlder,
            }));
        } finally {
            setLoadingOlder(false);
        }
    }, [fetchPage, loadingOlder]);

    const send = useCallback(
        async (body: string) => {
            const response = await fetch(`/groups/${groupId}/talk`, {
                method: 'POST',
                headers: { ...xsrfHeader(), 'Content-Type': 'application/json', Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ body }),
            });

            if (!response.ok) {
                throw new SendFailed(await bodyErrorOf(response));
            }

            append([(await response.json()) as TalkMessage]);
        },
        [append, groupId],
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

            setPage((current) => ({ ...current, messages: current.messages.filter((message) => message.id !== id) }));
        },
        [groupId],
    );

    return { page, loadingOlder, loadOlder, send, remove };
}

/** The `body` validation message from a 422, or null for any other refusal. */
async function bodyErrorOf(response: Response): Promise<string | null> {
    if (response.status !== 422) {
        return null;
    }

    try {
        const payload = (await response.json()) as { errors?: Record<string, string[]> };

        return payload.errors?.body?.[0] ?? null;
    } catch {
        return null;
    }
}
