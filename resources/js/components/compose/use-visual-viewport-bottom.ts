import { useLayoutEffect, useState } from 'react';

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

/**
 * Derived viewport geometry for the bar, in layout-viewport coordinates. `viewportBottom` is the y of
 * the visible-area bottom edge (offsetTop + height) — the bar is positioned by top so its bottom lands
 * there, which is robust whether iOS resizes the layout viewport, pans the visual viewport, or both.
 * `height`/`offsetTop` describe the visible band so a popover can clamp inside it; `keyboardOpen` is
 * true once the visible area is meaningfully shorter than the layout viewport.
 */
export interface ViewportMetrics {
    readonly viewportBottom: number;
    readonly height: number;
    readonly offsetTop: number;
    readonly keyboardOpen: boolean;
}

// A shrink beyond this many px reads as the on-screen keyboard (vs. browser chrome jitter).
const KEYBOARD_THRESHOLD = 100;
const ZERO: ViewportMetrics = { viewportBottom: 0, height: 0, offsetTop: 0, keyboardOpen: false };

// rAF-coalesce viewport events into one update per frame. Falls back to a timer where rAF is absent
// (node --test), so the observe logic is testable without a DOM.
const scheduleFrame: (cb: () => void) => number =
    typeof requestAnimationFrame === 'function' ? (cb) => requestAnimationFrame(cb) : (cb) => setTimeout(cb, 16) as unknown as number;
const cancelFrame: (id: number) => void =
    typeof cancelAnimationFrame === 'function' ? (id) => cancelAnimationFrame(id) : (id) => clearTimeout(id);

/**
 * Bar geometry from a viewport. `viewportBottom = offsetTop + height` is the layout-viewport y where
 * the bar's bottom edge should sit. Zero when no viewport is available (SSR/no window).
 */
export function computeViewportMetrics(viewport: ViewportLike | null | undefined): ViewportMetrics {
    if (!viewport) {
        return ZERO;
    }
    // Clamp to the layout viewport so a transient vv taller than the layout (or panned past the bottom)
    // never positions the bar below the fold.
    return {
        viewportBottom: Math.min(viewport.offsetTop + viewport.height, viewport.innerHeight),
        height: viewport.height,
        offsetTop: viewport.offsetTop,
        keyboardOpen: viewport.innerHeight - viewport.height > KEYBOARD_THRESHOLD,
    };
}

/**
 * Report the metrics now and on every resize/scroll (rAF-coalesced). Returns a cleanup that removes
 * the listeners and cancels any pending frame. A missing viewport reports zero once and subscribes to
 * nothing. (The window adapter fans 'resize'/'scroll' out to both visualViewport and window — see
 * {@link windowViewport} — so vv resize, vv scroll, and window scroll all drive updates.)
 */
export function observeViewportMetrics(
    viewport: ViewportLike | null | undefined,
    onChange: (metrics: ViewportMetrics) => void,
): () => void {
    onChange(computeViewportMetrics(viewport));
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
            onChange(computeViewportMetrics(viewport));
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

/**
 * Adapt the real window to ViewportLike. When visualViewport is missing, the band collapses to the
 * layout viewport (height = innerHeight, offsetTop = 0), which puts the bar at the layout bottom — the
 * spec-recommended fallback. Each subscription is fanned out to visualViewport *and* window so a
 * window scroll (which pans the visual viewport without a vv event on some engines) also updates.
 */
function windowViewport(): ViewportLike | null {
    if (typeof window === 'undefined') {
        return null;
    }
    const vv = window.visualViewport;
    return {
        get innerHeight() {
            return window.innerHeight;
        },
        get height() {
            return vv ? vv.height : window.innerHeight;
        },
        get offsetTop() {
            return vv ? vv.offsetTop : 0;
        },
        addEventListener: (type, listener) => {
            const l = listener as EventListener;
            vv?.addEventListener(type, l);
            window.addEventListener(type, l);
        },
        removeEventListener: (type, listener) => {
            const l = listener as EventListener;
            vv?.removeEventListener(type, l);
            window.removeEventListener(type, l);
        },
    };
}

/**
 * Track the keyboard-driven viewport metrics for a viewport-anchored fixed bar. Only subscribes while
 * `active`; returns zero (and tears down) otherwise. Pass `viewportOverride` in tests to drive it
 * without a browser — leave it undefined in production to read the real window. The override must be
 * referentially stable. Runs in a layout effect so the initial metrics land before paint (no flash).
 */
export function useVisualViewport(active: boolean, viewportOverride?: ViewportLike | null): ViewportMetrics {
    const [metrics, setMetrics] = useState<ViewportMetrics>(ZERO);

    useLayoutEffect(() => {
        if (!active) {
            setMetrics(ZERO);
            return;
        }
        const viewport = viewportOverride === undefined ? windowViewport() : viewportOverride;
        return observeViewportMetrics(viewport, setMetrics);
    }, [active, viewportOverride]);

    return active ? metrics : ZERO;
}
