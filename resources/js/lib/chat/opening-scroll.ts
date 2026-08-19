/**
 * The conversation pages place themselves when they open — at the foot, where a conversation is
 * read, or on the message a `?m=` link named — and this is what stops Inertia placing them again
 * afterwards.
 *
 * Inertia runs its own scroll handling once a visit's swap has resolved, which is after React's
 * layout effects, so a page that scrolls itself on mount is overruled by the `Scroll.reset()` that
 * follows. Only a cold load of the URL escaped, because that is the one arrival where Inertia leaves
 * the document scroll alone. Undoing the move on the next frame would also work, and would show the
 * reader a frame at the top of the history first, so the move is declined instead: under
 * `preserveScroll` Inertia restores `[scroll-region]` elements rather than the document, and this
 * app has none.
 *
 * Declined per *destination* rather than per link, because the pages that go wrong are exactly the
 * ones nobody remembered to mark, and the ways into a conversation are not one place. The pair is
 * held to the pages that scroll themselves by ConversationScrollRegistryTest.
 *
 * **Restores are not arrivals and keep their position deliberately.** A popstate, a back/forward
 * document load and a reload all reach `page.set`/`setQuietly` directly rather than through
 * `getPendingVisit`, so none of them consults this policy — they run `Scroll.restore()`, which puts
 * the reader back on the offset they left. That is what coming back should do, and it is why the
 * three are listed here rather than closed: the same three lanes revalidate-on-restore.ts answers.
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
    // It never travels further than the request: `setPreserveOptions` resolves it to a boolean
    // immediately before `page.set`. That ordering is the whole safety of returning a function here
    // — a function reaching `page.set` would read as truthy and switch `Scroll.reset()` off for
    // every page in the app.
    return { preserveScroll: (page) => SELF_PLACING.has(page.component) };
}
