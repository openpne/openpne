/**
 * What the talk page should tell the server it has read.
 *
 * The rule is deliberately about the newest **rendered** message, not the newest one the server
 * holds: the server resolves the tuple from the id it is given, so reporting anything else would
 * mark messages read that were never on screen. The client keeps its own monotonic guard as well —
 * the server has one too, but not sending a redundant call is cheaper than having it ignored, and it
 * is what turns a burst of poll merges into a single request.
 */

/** The id to report, or null when there is nothing new to say. */
export function nextReport(newestRenderedId: number | undefined, lastReported: number): number | null {
    if (newestRenderedId === undefined || newestRenderedId <= lastReported) {
        return null;
    }

    return newestRenderedId;
}
