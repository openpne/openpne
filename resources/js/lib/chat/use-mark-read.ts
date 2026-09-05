import { useEffect, useRef } from 'react';
import { xsrfHeader } from '@/lib/csrf';
import { requestUnreadRefresh } from '@/lib/unread-refresh';
import { nextReport, type ReportOutcome, settleReport } from './mark-read';

/** A poll burst merges several messages at once; one call covers them all. */
const DEBOUNCE_MS = 700;

/**
 * How a response settles (mark-read.ts owns what each outcome means). 5xx and the two transient
 * request codes are worth retrying; any other 4xx is terminal — a deleted message or a lost
 * membership will refuse the same id forever, and hammering it every few seconds helps nobody.
 */
function outcomeOf(status: number): ReportOutcome {
    if (status >= 200 && status < 300) {
        return 'ok';
    }

    return status >= 500 || status === 408 || status === 429 ? 'retryable' : 'terminal';
}

const RETRY_MS = 5_000;

/**
 * Fires while the tab is visible and the reader is at the foot of the conversation, since reading
 * back through history is not reading what has just arrived. The shared badge props are deliberately
 * not patched here — the shell's own refresh owns them — and an accepted report only asks it to
 * re-read now (lib/unread-refresh.ts).
 */
export function useMarkRead(readUrl: string, newestRenderedId: number | undefined, active: boolean) {
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
            let outcome: ReportOutcome;
            try {
                const response = await fetch(readUrl, {
                    method: 'POST',
                    headers: { ...xsrfHeader(), 'Content-Type': 'application/json', Accept: 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ messageId: id }),
                });
                outcome = outcomeOf(response.status);
            } catch {
                outcome = 'retryable';
            } finally {
                inFlight.current = false;
            }

            // Only an accepted report: a terminal 4xx moved no cursor, so the badge has nothing new
            // to say and asking would just be a wasted round trip on a screen that is already stuck.
            if (outcome === 'ok') {
                requestUnreadRefresh();
            }

            const settled = settleReport({ acked: acked.current, pending: pending.current }, id, outcome);
            acked.current = settled.state.acked;
            pending.current = settled.state.pending;
            if (settled.retry) {
                arm(outcome === 'retryable' ? RETRY_MS : DEBOUNCE_MS);
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
    }, [active, readUrl, newestRenderedId]);
}
