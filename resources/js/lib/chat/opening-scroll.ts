/**
 * Inertia runs its own scroll handling after React's layout effects, so a page that scrolls itself on
 * mount is overruled by the `Scroll.reset()` that follows unless the visit declines it
 * (docs/internals/group-talk.md, "Group talk"). A restore never consults this policy and keeps the
 * offset it left: a popstate, a back/forward load and a reload reach `page.set` directly rather
 * than through `getPendingVisit`.
 */

export interface ArrivalPage {
    component: string;
}

/** The pages that scroll themselves on mount, by the name the entry's `resolve` is given. */
const SELF_PLACING = new Set(['group/talk/index', 'message/conversation/index']);

/**
 * Inertia merges the configured options over the caller's, so a visit that asked to keep its scroll
 * has to be handed back untouched. The test is truthiness rather than presence because `<Link>`
 * passes `preserveScroll: false` on every navigation as a default parameter.
 */
export function conversationVisitOptions(options: { preserveScroll?: unknown }): {
    preserveScroll?: (page: ArrivalPage) => boolean;
} {
    if (options.preserveScroll) {
        return {};
    }

    // A callback so the page being arrived at decides: `setPreserveOptions` resolves it to a boolean
    // before `page.set`, where a function would read as truthy for every page in the app.
    return { preserveScroll: (page) => SELF_PLACING.has(page.component) };
}
