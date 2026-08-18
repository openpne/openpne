/**
 * Every page that comes back from history is shown as it was left, then re-read from the server.
 *
 * A restore renders stored state, not the server's: Inertia answers a popstate from its own history
 * state, the back/forward cache returns the whole document, and even a document the browser
 * re-requested for a back/forward arrival has Inertia swap the stored page state over the fresh
 * response (`router.handleBackForward`). All three land the member on numbers and previews from
 * before whatever they just did — a talk read behind a group list's unread counts, say.
 *
 * Applied to every page from the entry rather than opted into per page: the screens that go stale
 * are exactly the ones nobody remembered to wire, and a stray reload on one that could not is only
 * a background re-read. `router.reload()` is silent by design — async, no progress bar, scroll and
 * component state preserved — so the restored page simply settles to the present. It also never
 * fires `navigate` (a same-URL visit is a `replace`), so it cannot complete a restore below or
 * count as a step in back-nav's depth.
 *
 * The popstate half counts restores and reloads on the `navigate` Inertia fires once the restored
 * props are swapped in — ordered after that write rather than racing it, one per restore however
 * fast the member backs through them. A counted restore whose navigate never arrives (a browser
 * firing popstate on load) is absorbed by the bfcache pageshow when both announce the same return,
 * and otherwise spent on the next ordinary navigation as one redundant reload.
 */
import { createRestoreQueue } from './history-restore.ts';

export interface RestoreRevalidator {
    /** How this document itself arrived; a back/forward arrival renders stored state like a popstate's. */
    handleArrival(navigationType: string): void;
    /** The browser's `popstate`; `owned` is whether the entry carries Inertia page state. */
    handlePopstate(owned: boolean): void;
    /** Inertia's `navigate` — reloads when it completes a restore. */
    handleNavigate(): void;
    /** The browser's `pageshow`; `persisted` says the document came back whole from the bfcache. */
    handlePageshow(persisted: boolean): void;
}

export function createRestoreRevalidator(reload: () => void): RestoreRevalidator {
    const restores = createRestoreQueue();

    return {
        handleArrival(navigationType) {
            // Completed by the boot-time navigate. When Inertia had no stored entry to swap in the
            // props are already fresh and the reload is redundant, which is the cheap side of the
            // guess — the stale side leaves the member on yesterday's page.
            if (navigationType === 'back_forward') {
                restores.handlePopstate();
            }
        },
        handlePopstate(owned) {
            // A hash-only popstate has no Inertia state and gets no navigate; counting it would
            // leave the restore to be spent on an ordinary navigation later.
            if (owned) {
                restores.handlePopstate();
            }
        },
        handleNavigate() {
            if (restores.handleNavigate()) {
                reload();
            }
        },
        handlePageshow(persisted) {
            if (persisted) {
                // No navigate follows a bfcache restore — the document never left — so reload
                // directly. A browser that also fired popstate for this same return has counted a
                // restore no navigate will complete; absorb it so it is not spent on an ordinary
                // navigation later.
                restores.handleNavigate();
                reload();
            }
        },
    };
}

/** Inertia's router, narrowed to what revalidation needs. */
interface RevalidateRouter {
    on(type: 'navigate', callback: () => void): () => void;
    reload(): void;
}

let installed = false;

/**
 * Feed the revalidator from the router and the browser. The entry calls this (rather than the
 * module wiring itself on import) so importing the factory stays free of a router and a DOM.
 */
export function installRevalidateOnRestore(router: RevalidateRouter): void {
    if (installed) {
        return;
    }
    installed = true;

    const revalidator = createRestoreRevalidator(() => router.reload());

    const arrival = performance.getEntriesByType('navigation')[0] as PerformanceNavigationTiming | undefined;
    revalidator.handleArrival(arrival?.type ?? 'navigate');

    window.addEventListener('popstate', (event) =>
        revalidator.handlePopstate((event.state as { page?: unknown } | null)?.page !== undefined),
    );
    window.addEventListener('pageshow', (event) => revalidator.handlePageshow(event.persisted));
    router.on('navigate', () => revalidator.handleNavigate());
}
