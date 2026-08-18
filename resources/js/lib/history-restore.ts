/**
 * Whether the page now on screen was rebuilt from history rather than read from the server: Inertia
 * answers a popstate from its own stored page state, so a screen comes back saying exactly what it
 * said when it was left, however far its server state has moved since.
 *
 * The restore is recorded here rather than handled where it happens, because the page that cares is
 * not mounted when the popstate fires — it is what the popstate is about to swap in. The record
 * carries the URL it landed on, so only the page it is about can spend it: a record nobody asks
 * about expires by never matching again, without needing a second event to clear it.
 *
 * A consumer that outlives pages — the app-wide revalidation (revalidate-on-restore.ts) — cannot
 * use that record: it has to act *after* the restore has written its own stale props, which the
 * record says nothing about. {@link createRestoreQueue} is that shape of the same fact.
 */

export interface RestoreTracker {
    /** The browser's `popstate`, which Inertia answers from history; `url` is where it landed. */
    handlePopstate(url: string): void;
    /** True when the record is this page's; spending it clears it. */
    consume(url: string): boolean;
}

export function createRestoreTracker(): RestoreTracker {
    let restoredUrl: string | null = null;

    return {
        handlePopstate(url) {
            restoredUrl = url;
        },
        consume(url) {
            if (restoredUrl !== url) {
                return false;
            }
            restoredUrl = null;

            return true;
        },
    };
}

const tracker = createRestoreTracker();

/** True when this page is the one a popstate restored; reading it spends the record. */
export function consumeHistoryRestore(): boolean {
    return tracker.consume(window.location.href);
}

/**
 * Restores waiting for the `navigate` that completes them: Inertia fires it once the restored page
 * state has been swapped in, so a consumer reading from there is ordered after that write instead of
 * racing it.
 */
export interface RestoreQueue {
    /** A popstate on an entry Inertia owns — the `navigate` completing it is still to come. */
    handlePopstate(): void;
    /** Inertia's `navigate`; true when this one completes a restore. */
    handleNavigate(): boolean;
}

/**
 * A count, not a flag: holding back fires every popstate before any of their navigates arrive, and a
 * flag would answer only the first — leaving the last restore with no refresh ordered after its own
 * swap, so whether the counts end up right would come down to request timing. The same shape, and the
 * same reason, as `back-nav.ts`.
 */
export function createRestoreQueue(): RestoreQueue {
    let pending = 0;

    return {
        handlePopstate() {
            pending += 1;
        },
        handleNavigate() {
            if (pending === 0) {
                return false;
            }
            pending -= 1;

            return true;
        },
    };
}

let installed = false;

/**
 * Feed the tracker from the browser. The entry calls this (rather than the module wiring itself on
 * import) so importing the tracker stays free of a DOM.
 */
export function installHistoryRestore(): void {
    if (installed) {
        return;
    }
    installed = true;

    // `popstate` fires after the address has already moved, so this is where the restore landed.
    window.addEventListener('popstate', () => tracker.handlePopstate(window.location.href));
}
