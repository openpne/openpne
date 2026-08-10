/**
 * Whether the page now on screen was rebuilt from history rather than read from the server: Inertia
 * answers a popstate from its own stored page state, so a screen comes back saying exactly what it
 * said when it was left, however far its server state has moved since.
 *
 * The restore is recorded here rather than handled where it happens, because the page that cares is
 * not mounted when the popstate fires — it is what the popstate is about to swap in. Any visit the
 * member starts clears the record: whatever that visit lands on came from the server.
 */

export interface RestoreTracker {
    /** The browser's `popstate`, which Inertia answers from history. */
    handlePopstate(): void;
    /** A visit the member started; what it lands on is the server's answer. */
    handleVisitStart(): void;
    /** True once per restore: reading it clears it. */
    consume(): boolean;
}

export function createRestoreTracker(): RestoreTracker {
    let restored = false;

    return {
        handlePopstate() {
            restored = true;
        },
        handleVisitStart() {
            restored = false;
        },
        consume() {
            const wasRestored = restored;
            restored = false;

            return wasRestored;
        },
    };
}

const tracker = createRestoreTracker();

/**
 * True when this render is a page Inertia restored from history; reading it clears it, so the first
 * page to ask is the one that gets the answer. A restore nobody asks about is dropped by the next
 * visit rather than kept for a later page.
 */
export function consumeHistoryRestore(): boolean {
    return tracker.consume();
}

/** Inertia's router, narrowed to the one event this needs. */
interface StartEvents {
    on(type: 'start', callback: () => void): () => void;
}

let installed = false;

/**
 * Feed the tracker from the router and the browser. The entry calls this (rather than the module
 * wiring itself on import) so importing the tracker stays free of a router and a DOM.
 */
export function installHistoryRestore(router: StartEvents): void {
    if (installed) {
        return;
    }
    installed = true;

    window.addEventListener('popstate', () => tracker.handlePopstate());
    router.on('start', () => tracker.handleVisitStart());
}
