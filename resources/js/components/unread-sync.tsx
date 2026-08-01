import { router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import type { PageProps, UnreadCounts } from '@/types';

/** How often a visible tab re-reads the counts. */
const INTERVAL_MS = 60_000;

/**
 * Keeps the shared `unread` counts live while a tab sits open. Non-visual; mounted once in the app
 * shell, which persists across SPA navigations (auth pages have no layout, so nothing polls there).
 *
 * Refreshes are pushed back into the shared prop, so every consumer — the badges, and the title
 * callback reading `page.props.unread` (app.tsx) — re-renders from the one source it already reads.
 * Not Inertia's `usePoll`: that still fires on a hidden tab (every tenth interval) and does not
 * refresh on return to the tab, which is the moment a stale badge is seen.
 */
export function UnreadSync() {
    const unread = usePage<PageProps>().props.unread;
    const signedIn = unread != null;

    useEffect(() => {
        if (!signedIn) {
            return;
        }

        let inFlight: AbortController | null = null;

        const refresh = () => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            inFlight?.abort();
            const controller = new AbortController();
            inFlight = controller;

            fetch('/unread-counts', {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((res) => {
                    if (!res.ok) {
                        throw new Error(`unread counts failed: ${res.status}`);
                    }

                    return res.json() as Promise<UnreadCounts>;
                })
                .then((counts) => router.replaceProp('unread', counts))
                .catch(() => {
                    // Keep what we have and stay quiet: a refused or dropped refresh is not news to
                    // the member, and an expired session is answered by the next real navigation.
                });
        };

        const timer = setInterval(refresh, INTERVAL_MS);
        document.addEventListener('visibilitychange', refresh);

        return () => {
            clearInterval(timer);
            document.removeEventListener('visibilitychange', refresh);
            inFlight?.abort();
        };
    }, [signedIn]);

    return null;
}
