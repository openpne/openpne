/**
 * The id reported is the newest rendered message, and the client does not order ids — direction is
 * the server's monotonic guard's job (docs/internals/group-talk.md, "Mark-read is client-named,
 * server-resolved, and monotonic"). A 5xx or a network reject retries the same id; a terminal 4xx
 * settles it, so only a different newest message is worth reporting.
 */

export type ReportOutcome = 'ok' | 'retryable' | 'terminal';

export interface ReportState {
    /** The last id settled with the server: confirmed by a 2xx, or abandoned as terminal. */
    acked: number;
    pending: number | null;
}

/** The id to report, or null when the server already acknowledged it. */
export function nextReport(newestRenderedId: number | undefined, acked: number): number | null {
    if (newestRenderedId === undefined || newestRenderedId === acked) {
        return null;
    }

    return newestRenderedId;
}

/** `retry` asks the caller to re-arm its timer. */
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
