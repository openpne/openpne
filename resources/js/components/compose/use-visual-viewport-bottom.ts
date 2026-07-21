import { useEffect, useState } from 'react';

/**
 * The slice of the visual viewport the fixed mobile toolbar needs, narrowed to an injectable
 * interface so the logic can be unit-tested without a browser (use-visual-viewport-bottom.test.ts).
 * `innerHeight` is the layout viewport height (window.innerHeight); `height`/`offsetTop` come from
 * window.visualViewport and shrink/shift when the on-screen keyboard opens.
 */
export interface ViewportLike {
    readonly innerHeight: number;
    readonly height: number;
    readonly offsetTop: number;
    addEventListener(type: string, listener: () => void): void;
    removeEventListener(type: string, listener: () => void): void;
}

// rAF-coalesce viewport events into one update per frame. Falls back to a timer where rAF is absent
// (node --test), so the observe logic is testable without a DOM.
const scheduleFrame: (cb: () => void) => number =
    typeof requestAnimationFrame === 'function' ? (cb) => requestAnimationFrame(cb) : (cb) => setTimeout(cb, 16) as unknown as number;
const cancelFrame: (id: number) => void =
    typeof cancelAnimationFrame === 'function' ? (id) => cancelAnimationFrame(id) : (id) => clearTimeout(id);

/**
 * Pixels between the visual viewport's bottom edge and the layout viewport's bottom — i.e. the height
 * the keyboard (and any browser bottom chrome) covers. Clamped at 0; 0 when no viewport is available.
 */
export function computeViewportBottom(viewport: ViewportLike | null | undefined): number {
    if (!viewport) {
        return 0;
    }
    return Math.max(0, viewport.innerHeight - viewport.height - viewport.offsetTop);
}

/**
 * Report the bottom offset now and on every viewport resize/scroll (rAF-coalesced). Returns a cleanup
 * that removes the listeners and cancels any pending frame. A missing viewport reports 0 once and
 * subscribes to nothing.
 */
export function observeViewportBottom(viewport: ViewportLike | null | undefined, onChange: (bottom: number) => void): () => void {
    onChange(computeViewportBottom(viewport));
    if (!viewport) {
        return () => {};
    }
    let frame: number | null = null;
    const update = () => {
        if (frame !== null) {
            return;
        }
        frame = scheduleFrame(() => {
            frame = null;
            onChange(computeViewportBottom(viewport));
        });
    };
    viewport.addEventListener('resize', update);
    viewport.addEventListener('scroll', update);
    return () => {
        if (frame !== null) {
            cancelFrame(frame);
        }
        viewport.removeEventListener('resize', update);
        viewport.removeEventListener('scroll', update);
    };
}

/** Adapt the real window/visualViewport to ViewportLike, or null when visualViewport is unavailable. */
function windowViewport(): ViewportLike | null {
    if (typeof window === 'undefined' || !window.visualViewport) {
        return null;
    }
    const vv = window.visualViewport;
    return {
        get innerHeight() {
            return window.innerHeight;
        },
        get height() {
            return vv.height;
        },
        get offsetTop() {
            return vv.offsetTop;
        },
        addEventListener: (type, listener) => vv.addEventListener(type, listener as EventListener),
        removeEventListener: (type, listener) => vv.removeEventListener(type, listener as EventListener),
    };
}

/**
 * Track the keyboard-driven bottom offset for a viewport-anchored fixed bar. Only subscribes while
 * `active`; returns 0 (and tears down) otherwise. Pass `viewportOverride` in tests to drive it without
 * a browser — leave it undefined in production to read the real window (null when visualViewport is
 * missing → constant 0). The override must be referentially stable.
 */
export function useVisualViewportBottom(active: boolean, viewportOverride?: ViewportLike | null): number {
    const [bottom, setBottom] = useState(0);

    useEffect(() => {
        if (!active) {
            setBottom(0);
            return;
        }
        const viewport = viewportOverride === undefined ? windowViewport() : viewportOverride;
        return observeViewportBottom(viewport, setBottom);
    }, [active, viewportOverride]);

    return active ? bottom : 0;
}
