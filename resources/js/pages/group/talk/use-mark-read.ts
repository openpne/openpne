import { useEffect, useRef } from 'react';
import { xsrfHeader } from '@/lib/csrf';
import { nextReport } from './mark-read';

/** A poll burst merges several messages at once; one call covers them all. */
const DEBOUNCE_MS = 700;

/**
 * Reports the newest rendered message as read.
 *
 * Fires while the tab is visible and the reader is at the foot of the conversation — reading back
 * through history is not reading what has just arrived, and marking it read would drop a badge for
 * messages still below the fold. A tab that was hidden when messages landed reports on its way back,
 * which is the moment the reader actually sees them.
 *
 * The shared badge props are deliberately NOT patched here: the shell's own refresh owns them, and a
 * second writer would make a stale count look authoritative.
 */
export function useMarkRead(groupId: number, newestRenderedId: number | undefined, active: boolean) {
    const lastReported = useRef(0);
    const pending = useRef<number | null>(null);

    useEffect(() => {
        pending.current = active ? nextReport(newestRenderedId, lastReported.current) : null;

        let timer: ReturnType<typeof setTimeout> | null = null;

        const flush = () => {
            const id = pending.current;
            if (id === null || document.visibilityState !== 'visible') {
                return;
            }

            pending.current = null;
            lastReported.current = id;

            void fetch(`/groups/${groupId}/talk/read`, {
                method: 'POST',
                headers: { ...xsrfHeader(), 'Content-Type': 'application/json', Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ messageId: id }),
            }).catch(() => {
                // A dropped mark-read is not worth telling the reader about: the next arrival reports
                // a newer id, and the server's monotonic guard makes a replay free.
            });
        };

        if (pending.current !== null) {
            timer = setTimeout(flush, DEBOUNCE_MS);
        }
        document.addEventListener('visibilitychange', flush);

        return () => {
            if (timer !== null) {
                clearTimeout(timer);
            }
            document.removeEventListener('visibilitychange', flush);
        };
    }, [active, groupId, newestRenderedId]);
}
