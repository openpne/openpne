/**
 * Back navigation for the mobile detail bar: how far into this SPA session the current page sits, so
 * the bar can offer the session's own back before falling back to the structural parent.
 *
 * Depth is counted from Inertia's `navigate` event, which also fires for the initial load and for the
 * page a popstate lands on — neither is a step forward, so both are discounted here. What is left can
 * still drift below the truth (a forward popstate, a full page load resets it), and that direction is
 * the safe one: too little depth degrades to the crumb parent, never to a control that leaves the app.
 */

export interface BackTracker {
    /** Inertia's `navigate`. */
    handleNavigate(): void;
    /** The browser's `popstate`, which precedes the `navigate` it causes. */
    handlePopstate(): void;
    hasInAppHistory(): boolean;
    subscribe(callback: () => void): () => void;
    /** Depth as a changing primitive, for useSyncExternalStore. */
    getSnapshot(): number;
}

export function createBackTracker(): BackTracker {
    let depth = 0;
    let started = false;
    let popped = false;
    const subscribers = new Set<() => void>();

    const notify = () => {
        for (const callback of subscribers) {
            callback();
        }
    };

    return {
        handleNavigate() {
            if (popped) {
                popped = false;
            } else if (!started) {
                started = true;
            } else {
                depth += 1;
            }
            notify();
        },
        handlePopstate() {
            popped = true;
            depth = Math.max(0, depth - 1);
            notify();
        },
        hasInAppHistory: () => depth > 0,
        subscribe(callback) {
            subscribers.add(callback);

            return () => {
                subscribers.delete(callback);
            };
        },
        getSnapshot: () => depth,
    };
}

const tracker = createBackTracker();

/** The tracker the bar subscribes to; `installBackNav` is what feeds it. */
export function backTracker(): BackTracker {
    return tracker;
}

/** Inertia's router, narrowed to the one event this needs. */
interface NavigateEvents {
    on(type: 'navigate', callback: () => void): () => void;
}

let installed = false;

/**
 * Feed the tracker from the router and the browser. The entry calls this (rather than the module
 * wiring itself on import) so importing the tracker stays free of a router and a DOM.
 */
export function installBackNav(router: NavigateEvents): void {
    if (installed) {
        return;
    }
    installed = true;

    router.on('navigate', () => tracker.handleNavigate());
    window.addEventListener('popstate', () => tracker.handlePopstate());
}

export type BackTarget = { type: 'history' } | { type: 'href'; href: string };

/**
 * Where back goes: the session's own history when there is any, else the structural parent — the
 * nearest crumb, or the dashboard for a page that carries no crumbs.
 */
export function backTarget(hasInAppHistory: boolean, context?: readonly { href: string }[]): BackTarget {
    if (hasInAppHistory) {
        return { type: 'history' };
    }

    return { type: 'href', href: context?.at(-1)?.href ?? '/dashboard' };
}
