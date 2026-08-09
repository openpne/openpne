import { router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { clearAppBadge, resumeRegistration, setAppBadge } from '@/lib/push';
import type { PageProps, UnreadCounts } from '@/types';

/** How often a visible tab re-reads the counts. */
const INTERVAL_MS = 60_000;

/** Mirror the OS app-icon badge to the notifications count (both calls feature-detected inside). */
function writeBadge(count: number): void {
    if (count > 0) {
        setAppBadge(count);
    } else {
        clearAppBadge();
    }
}

/**
 * Keeps the shared `unread` counts live while a tab sits open, and mirrors the notifications count to
 * the OS app-icon badge. Non-visual; mounted once in the app shell, which persists across SPA
 * navigations (auth pages have no layout, so nothing polls there).
 *
 * Refreshes are pushed back into the shared prop, so every consumer — the badges, and the title
 * callback reading `page.props.unread` (app.tsx) — re-renders from the one source it already reads.
 * Not Inertia's `usePoll`: that still fires on a hidden tab (every tenth interval) and does not
 * refresh on return to the tab, which is the moment a stale badge is seen.
 */
export function UnreadSync() {
    const unread = usePage<PageProps>().props.unread;
    const signedIn = unread != null;

    // App-icon badge on every foreground change: mark-all, opening the feed and navigation all refresh
    // this prop. The fetch path below covers the other case — an unchanged value that a background
    // push left a stale badge under, which this keyed effect would not re-fire for.
    useEffect(() => {
        if (signedIn) {
            writeBadge(unread?.notifications ?? 0);
        }
    }, [signedIn, unread?.notifications]);

    // Opted-in devices re-fetch the worker each visit; resumeRegistration installs nothing otherwise.
    useEffect(() => {
        resumeRegistration();
    }, []);

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
                .then((counts) => {
                    router.replaceProp('unread', counts);
                    // Overwrite the badge unconditionally from the authoritative count: a background push
                    // may have written a stale one, and if the count is unchanged the keyed effect above
                    // will not re-fire to correct it.
                    writeBadge(counts.notifications);
                })
                .catch(() => {
                    // Keep what we have and stay quiet: a refused or dropped refresh is not news to
                    // the member, and an expired session is answered by the next real navigation.
                });
        };

        const timer = setInterval(refresh, INTERVAL_MS);
        document.addEventListener('visibilitychange', refresh);

        // The worker asks a visible tab to re-sync rather than writing the badge itself, so the
        // foreground's authoritative count always wins over a late push's.
        const sw = 'serviceWorker' in navigator ? navigator.serviceWorker : null;
        const onMessage = (event: MessageEvent) => {
            if (event.data?.type === 'refresh-unread') {
                refresh();
            }
        };
        sw?.addEventListener('message', onMessage);

        return () => {
            clearInterval(timer);
            document.removeEventListener('visibilitychange', refresh);
            sw?.removeEventListener('message', onMessage);
            inFlight?.abort();
        };
    }, [signedIn]);

    return null;
}
