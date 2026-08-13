import { useEffect, useRef } from 'react';
import { xsrfHeader } from '@/lib/csrf';
import { nextReport, settleReport } from './mark-read';

/** A poll burst merges several messages at once; one call covers them all. */
const DEBOUNCE_MS = 700;

/** How long a failed report waits before the same id is tried again. */
const RETRY_MS = 5_000;

/**
 * Reports the newest rendered message as read.
 *
 * Fires while the tab is visible and the reader is at the foot of the conversation — reading back
 * through history is not reading what has just arrived, and marking it read would drop a badge for
 * messages still below the fold. A tab that was hidden when messages landed reports on its way back,
 * which is the moment the reader actually sees them.
 *
 * The position only advances on a 2xx (mark-read.ts owns that rule); a failed report re-arms and
 * retries the same id, since nothing else would ever resend it. The shared badge props are
 * deliberately NOT patched here: the shell's own refresh owns them, and a second writer would make
 * a stale count look authoritative.
 */
export function useMarkRead(groupId: number, newestRenderedId: number | undefined, active: boolean) {
    const acked = useRef(0);
    const pending = useRef<number | null>(null);
    const inFlight = useRef(false);

    useEffect(() => {
        pending.current = active ? nextReport(newestRenderedId, acked.current) : null;

        let timer: ReturnType<typeof setTimeout> | null = null;
        let gone = false;

        const arm = (delay: number) => {
            if (gone) {
                return;
            }
            if (timer !== null) {
                clearTimeout(timer);
            }
            timer = setTimeout(flush, delay);
        };

        const flush = async () => {
            const id = pending.current;
            if (id === null || document.visibilityState !== 'visible') {
                return;
            }
            if (inFlight.current) {
                // A report is already on the wire (an earlier effect's); look again shortly.
                arm(DEBOUNCE_MS);

                return;
            }

            inFlight.current = true;
            let ok = false;
            try {
                const response = await fetch(`/groups/${groupId}/talk/read`, {
                    method: 'POST',
                    headers: { ...xsrfHeader(), 'Content-Type': 'application/json', Accept: 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ messageId: id }),
                });
                ok = response.ok;
            } catch {
                ok = false;
            } finally {
                inFlight.current = false;
            }

            const settled = settleReport({ acked: acked.current, pending: pending.current }, id, ok);
            acked.current = settled.state.acked;
            pending.current = settled.state.pending;
            if (settled.retry) {
                arm(ok ? DEBOUNCE_MS : RETRY_MS);
            }
        };

        if (pending.current !== null) {
            arm(DEBOUNCE_MS);
        }
        document.addEventListener('visibilitychange', flush);

        return () => {
            gone = true;
            if (timer !== null) {
                clearTimeout(timer);
            }
            document.removeEventListener('visibilitychange', flush);
        };
    }, [active, groupId, newestRenderedId]);
}
