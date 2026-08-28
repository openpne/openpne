import type { ArrivalPage } from '@/lib/chat/opening-scroll';

/**
 * Holds a page Inertia has just scrolled to the top there for the moment after.
 *
 * Inertia's reset is one `window.scrollTo(0, 0)` after the swap. On iOS the window's position is
 * owned outside the page and reported back asynchronously, and in a standalone PWA a page opened
 * from one left a little scrolled was seen to come up sitting where that one was left — and, past
 * the receding chrome's hide line, with the bars gone, since the hooks read the move as a scroll
 * down. Not reproduced elsewhere; the report and the platform notes are on the worklog side.
 *
 * So after an arrival Inertia placed at the top, a scroll event reporting the page off it is
 * answered with the same scrollTo again. Answered in the event itself, which is before the
 * direction and seam hooks read the position on the next frame, so they never see the bounce. The
 * hold ends at the reader's first input — a touch, wheel or key makes the scroll theirs — at the
 * next arrival, at a popstate (the restore it brings puts the page back where it was left, and a
 * hold still running would undo that on the next frame), or after a short window. It never starts
 * for an arrival that keeps its position: a `preserveScroll` visit, a history restore, or a hash
 * URL Inertia scrolls to the anchor of.
 *
 * Which arrivals Inertia resets is known exactly where it asks: the `preserveScroll` callback the
 * visit defaults hand it (opening-scroll.ts), resolved against the page being arrived at right
 * before it is set. `expect()` is called from there, and the visit's `success` or `error` — both
 * fired once the page is set and scrolled, and only by a visit — spends it. Not `navigate`: a
 * same-URL visit replaces its history entry and fires none, and a restore fires one with no visit
 * behind it, so a record left by the former would be spent by the latter and the hold would undo
 * the restore. `finish` clears whatever a visit left unspent.
 */

/** The arrivals Inertia scrolls to the top, one visit at a time. */
export interface ResetSettler {
    /** The visit being resolved will be scrolled to the top by Inertia. */
    expect(): void;
    /** The visit's `success` or `error`; true when it completes an expected reset. Spending the record clears it. */
    arrive(): boolean;
    /** The visit's `finish`: a record it left unspent is nobody else's. */
    finish(): void;
}

export function createResetSettler(): ResetSettler {
    let expected = false;

    return {
        expect() {
            expected = true;
        },
        arrive() {
            if (!expected) {
                return false;
            }
            expected = false;

            return true;
        },
        finish() {
            expected = false;
        },
    };
}

type PreserveScroll = (page: ArrivalPage) => boolean;

/**
 * The visit options with their `preserveScroll` callback reporting a `false` to the settler. Options
 * without one are a visit that asked to keep its scroll (see conversationVisitOptions) and pass
 * through untouched: Inertia will not reset them, so there is nothing to expect.
 */
export function expectingReset<T extends { preserveScroll?: PreserveScroll }>(options: T, settler: Pick<ResetSettler, 'expect'>): T {
    const decide = options.preserveScroll;
    if (!decide) {
        return options;
    }

    return {
        ...options,
        preserveScroll: (page: ArrivalPage) => {
            const keep = decide(page);
            if (!keep) {
                settler.expect();
            }

            return keep;
        },
    };
}

/** How long after the arrival a bounce is still answered. */
const HOLD_MS = 1000;

/** The reader taking over: from here the scroll is theirs. */
const READER_INPUT = ['touchstart', 'pointerdown', 'wheel', 'keydown'] as const;

/** Inertia's router, narrowed to the visit events this needs. */
interface VisitEvents {
    on(type: 'success' | 'error' | 'finish', callback: () => void): () => void;
}

/**
 * Wire the settler to the router and the window. The entry calls this and hands the returned
 * settler to its visit defaults, so the module stays free of a router and a DOM on import.
 */
export function installScrollResetSettle(router: VisitEvents): Pick<ResetSettler, 'expect'> {
    const settler = createResetSettler();
    let release: (() => void) | null = null;

    const end = () => {
        release?.();
        release = null;
    };

    const hold = () => {
        const toTop = () => {
            if (window.scrollY > 0) {
                window.scrollTo(0, 0);
            }
        };
        const timer = window.setTimeout(end, HOLD_MS);
        window.addEventListener('scroll', toTop, { passive: true });
        READER_INPUT.forEach((type) => window.addEventListener(type, end, { capture: true, passive: true }));
        release = () => {
            window.clearTimeout(timer);
            window.removeEventListener('scroll', toTop);
            READER_INPUT.forEach((type) => window.removeEventListener(type, end, { capture: true }));
        };
        toTop();
    };

    const arrive = () => {
        end();
        // A hash URL is one Inertia scrolls to the anchor of instead of the top.
        if (settler.arrive() && !window.location.hash) {
            hold();
        }
    };
    router.on('success', arrive);
    router.on('error', arrive);
    router.on('finish', () => settler.finish());
    // Before Inertia's own popstate work, which restores on a later frame: registered after its
    // listener, and the restore is scheduled from a promise either way.
    window.addEventListener('popstate', end);

    return settler;
}
