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
 * A report is *acknowledged* only by a 2xx response. Anything else — a network reject, a 419, a
 * 5xx — leaves the position unconfirmed so the same id is retried: "read the page and it is read"
 * is the core loop, and a silently dropped first report would strand the badge until the next
 * arrival.
 */

export interface ReportState {
    /** The last id the server confirmed with a 2xx. */
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
export function settleReport(state: ReportState, sentId: number, ok: boolean): { state: ReportState; retry: boolean } {
    if (ok) {
        return {
            state: { acked: sentId, pending: state.pending === sentId ? null : state.pending },
            retry: state.pending !== null && state.pending !== sentId,
        };
    }

    // Unconfirmed: keep the sent id claimable again unless something newer already queued behind it.
    return { state: { acked: state.acked, pending: state.pending ?? sentId }, retry: true };
}
