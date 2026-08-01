import { router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { setUnreadTitleCount } from '@/lib/unread-title';
import type { PageProps, UnreadCounts } from '@/types';

/** How often a visible tab re-reads the counts. */
const INTERVAL_MS = 60_000;

/**
 * Keeps the shared `unread` counts live while a tab sits open, and mirrors the unread-notification
 * count into the document title. Non-visual; mounted once in the app shell, which persists across
 * SPA navigations (auth pages have no layout, so nothing polls there).
 *
 * Refreshes are pushed back into the shared prop, so every badge re-renders from the one source it
 * already reads. Not Inertia's `usePoll`: that still fires on a hidden tab (every tenth interval)
 * and does not refresh on return to the tab, which is the moment a stale badge is seen.
 */
export function UnreadSync() {
    const unread = usePage<PageProps>().props.unread;

    // Written during render, not from an effect: the head manager may flush the title for this
    // commit before effects run, and it reads the count through the Inertia title callback. The
    // write is idempotent, so StrictMode's double render is harmless. A guest resets it to 0, so
    // no prefix survives a logout.
    setUnreadTitleCount(unread?.notifications ?? 0);

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
