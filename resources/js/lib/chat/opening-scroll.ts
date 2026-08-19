/**
 * The conversation pages place themselves when they open — at the foot, where a conversation is
 * read, or on the message a `?m=` link named — and this is what stops Inertia placing them again
 * afterwards.
 *
 * Inertia runs its own scroll handling once a visit's swap has resolved, which is after React's
 * layout effects: `Scroll.reset()` on an ordinary visit, `Scroll.restoreDocument()` on a reload. A
 * page that scrolls itself on mount is therefore always overruled, and the only path that escapes is
 * a cold load of the URL — the one where Inertia touches the document scroll at all. Undoing the
 * move on the next frame would also work, and would show the reader a frame at the top of the
 * history first, so the move is declined instead: under `preserveScroll` Inertia leaves the document
 * alone, restoring `[scroll-region]` elements instead, which this app has none of.
 *
 * Declined per *destination* rather than per link, because the pages that go wrong are exactly the
 * ones nobody remembered to mark, and the ways into a conversation are not one place.
 */

/** Inertia's page object, narrowed to what this policy reads. */
export interface ArrivalPage {
    component: string;
}

/** The pages that scroll themselves on mount, by the name the entry's `resolve` is given. */
const SELF_PLACING = new Set(['group/talk/index', 'message/conversation/index']);

/**
 * What to add to a visit's options, given what it already asks for.
 *
 * Inertia merges the configured options *over* the caller's (`getPendingVisit`), so a visit that
 * asked to keep its scroll — a `preserveScroll` link, a form post staying put, `router.reload()` —
 * has to be handed back untouched. The test is truthiness rather than presence because `<Link>`
 * passes `preserveScroll: false` on every navigation as a default parameter: "said nothing" and
 * "said no" arrive here as the same value, and both want the same answer.
 */
export function conversationVisitOptions(options: { preserveScroll?: unknown }): {
    preserveScroll?: (page: ArrivalPage) => boolean;
} {
    if (options.preserveScroll) {
        return {};
    }

    // A callback, so it is resolved against the response and the page being *arrived at* decides.
    return { preserveScroll: (page) => SELF_PLACING.has(page.component) };
}
