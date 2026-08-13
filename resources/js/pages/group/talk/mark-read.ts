/**
 * What the talk page should tell the server it has read, and how a report settles.
 *
 * The id reported is the newest **rendered** message — the server resolves the tuple from the id it
 * is given, so reporting anything else would mark messages read that were never on screen. The
 * client does NOT order ids: the conversation's order is the `(created_at, id)` tuple and migrated
 * ids are not monotonic in time, so "smaller id" does not mean "older". The client only avoids
 * repeating the id the server has already acknowledged; direction is the server's monotonic guard's
 * job, and a backward report is a free no-op there.
 *
 * A report is *acknowledged* only by a 2xx response, and a failure is not one thing. A network
 * reject or a 5xx is **retryable** — the same id is tried again, since nothing else would resend a
 * lost first report and "read the page and it is read" is the core loop. A terminal 4xx — the
 * message was deleted after rendering (a 404 the server calls an ordinary race), the membership
 * went away in another tab, an expired session — is **terminal**: retrying the same id forever
 * cannot succeed, so the id is settled as spoken-for and only a *different* newest message is worth
 * reporting again.
 */

export type ReportOutcome = 'ok' | 'retryable' | 'terminal';

export interface ReportState {
    /** The last id settled with the server: confirmed by a 2xx, or abandoned as terminal. */
    acked: number;
    /** The id waiting to be sent, or null. */
    pending: number | null;
}

/** The id to report, or null when the server already acknowledged it. */
export function nextReport(newestRenderedId: number | undefined, acked: number): number | null {
    if (newestRenderedId === undefined || newestRenderedId === acked) {
        return null;
    }

    return newestRenderedId;
}

/** Fold a finished request into the state; `retry` asks the caller to re-arm its timer. */
export function settleReport(state: ReportState, sentId: number, outcome: ReportOutcome): { state: ReportState; retry: boolean } {
    if (outcome === 'retryable') {
        // Unconfirmed: keep the sent id claimable unless something newer already queued behind it.
        return { state: { acked: state.acked, pending: state.pending ?? sentId }, retry: true };
    }

    // 2xx and terminal settle the same shape: this id is done — confirmed or unwinnable — and only
    // a newer queued id keeps the caller going.
    return {
        state: { acked: sentId, pending: state.pending === sentId ? null : state.pending },
        retry: state.pending !== null && state.pending !== sentId,
    };
}
