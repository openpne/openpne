/**
 * Depth is counted from Inertia's `navigate`, which also fires for the initial load and for the page
 * a popstate lands on — neither is a step forward, so both are discounted here. What is left can
 * drift below the truth, which is the safe direction: too little depth degrades to the crumb parent,
 * never to a control that leaves the app.
 */

export interface BackTracker {
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
    // A counter, not a flag: rapid back presses fire several popstates before their navigates
    // arrive, and a flag would absorb them all and over-count the depth.
    let pendingPops = 0;
    const subscribers = new Set<() => void>();

    const notify = () => {
        for (const callback of subscribers) {
            callback();
        }
    };

    return {
        handleNavigate() {
            if (pendingPops > 0) {
                pendingPops -= 1;
            } else if (!started) {
                started = true;
            } else {
                depth += 1;
            }
            notify();
        },
        handlePopstate() {
            pendingPops += 1;
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

export function backTracker(): BackTracker {
    return tracker;
}

interface NavigateEvents {
    on(type: 'navigate', callback: () => void): () => void;
}

let installed = false;

/**
 * The entry calls this rather than the module wiring itself on import, so importing the tracker
 * stays free of a router and a DOM.
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

export function backTarget(hasInAppHistory: boolean, context?: readonly { href: string }[]): BackTarget {
    if (hasInAppHistory) {
        return { type: 'history' };
    }

    return { type: 'href', href: context?.at(-1)?.href ?? '/' };
}
