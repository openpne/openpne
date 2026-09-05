/**
 * A restore renders stored state, not the server's: a popstate is answered from Inertia's history
 * state, the bfcache returns the whole document, and a re-requested back/forward document has the
 * stored state swapped over the fresh response. `router.reload()` fires no `navigate` — a same-URL
 * visit is a `replace` — so it cannot complete a restore or count as a back-nav step.
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
            // A redundant reload is the cheap side of the guess; the stale side leaves the member on
            // yesterday's page.
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
                // No navigate follows a bfcache restore, so a popstate counted for the same return has
                // to be absorbed here or it is spent on an ordinary navigation later.
                restores.handleNavigate();
                reload();
            }
        },
    };
}

interface RevalidateRouter {
    on(type: 'navigate', callback: () => void): () => void;
    reload(): void;
}

let installed = false;

/**
 * The entry calls this rather than the module wiring itself on import, so importing the factory
 * stays free of a router and a DOM. Installed for every page rather than opted into per page: the
 * screens that go stale are the ones nobody remembered to wire, and a stray reload is only a
 * background re-read.
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
