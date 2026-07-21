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

/**
 * Derived viewport geometry for the bar. `bottom` is the keyboard-covered height the bar sits above;
 * `height`/`offsetTop` describe the visible band so a popover can clamp itself inside it. `height` is
 * 0 when no viewport is available (the consumer then falls back to its own cap).
 */
export interface ViewportMetrics {
    readonly bottom: number;
    readonly height: number;
    readonly offsetTop: number;
}

const ZERO: ViewportMetrics = { bottom: 0, height: 0, offsetTop: 0 };

// rAF-coalesce viewport events into one update per frame. Falls back to a timer where rAF is absent
// (node --test), so the observe logic is testable without a DOM.
const scheduleFrame: (cb: () => void) => number =
    typeof requestAnimationFrame === 'function' ? (cb) => requestAnimationFrame(cb) : (cb) => setTimeout(cb, 16) as unknown as number;
const cancelFrame: (id: number) => void =
    typeof cancelAnimationFrame === 'function' ? (id) => cancelAnimationFrame(id) : (id) => clearTimeout(id);

/**
 * Bottom offset (keyboard-covered height), clamped at 0, plus the visible band dimensions. 0/0/0 when
 * no viewport is available.
 */
export function computeViewportMetrics(viewport: ViewportLike | null | undefined): ViewportMetrics {
    if (!viewport) {
        return ZERO;
    }
    return {
        bottom: Math.max(0, viewport.innerHeight - viewport.height - viewport.offsetTop),
        height: viewport.height,
        offsetTop: viewport.offsetTop,
    };
}

/**
 * Report the metrics now and on every viewport resize/scroll (rAF-coalesced). Returns a cleanup that
 * removes the listeners and cancels any pending frame. A missing viewport reports zero once and
 * subscribes to nothing.
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
 * Track the keyboard-driven viewport metrics for a viewport-anchored fixed bar. Only subscribes while
 * `active`; returns zero (and tears down) otherwise. Pass `viewportOverride` in tests to drive it
 * without a browser — leave it undefined in production to read the real window (null when
 * visualViewport is missing → constant zero). The override must be referentially stable.
 */
export function useVisualViewport(active: boolean, viewportOverride?: ViewportLike | null): ViewportMetrics {
    const [metrics, setMetrics] = useState<ViewportMetrics>(ZERO);

    useEffect(() => {
        if (!active) {
            setMetrics(ZERO);
            return;
        }
        const viewport = viewportOverride === undefined ? windowViewport() : viewportOverride;
        return observeViewportMetrics(viewport, setMetrics);
    }, [active, viewportOverride]);

    return active ? metrics : ZERO;
}
