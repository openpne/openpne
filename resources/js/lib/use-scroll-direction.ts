import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export type ScrollDirection = 'up' | 'down';

/** A direction plus the y it was decided at — travel is measured from there, not from the last event. */
export interface ScrollState {
    direction: ScrollDirection;
    anchorY: number;
}

export interface ScrollThresholds {
    /** Travel from the anchor that flips the direction. */
    threshold: number;
    /**
     * How far down the first flip to 'down' waits. A sticky bar holds its slot in the flow, so taking
     * it away while that slot is still on screen leaves a blank band above the page — it is worth
     * hiding only once it has scrolled past. A reveal deep in the page is already past it, so the
     * re-hide that follows costs the plain threshold.
     */
    minDownY: number;
}

/**
 * The direction after the page reached `y`. A flip needs `threshold` of travel from the point the
 * current direction was decided at: a slow drag arrives as a run of 3px events that each fall short
 * on their own and must still add up to a flip. Travel that continues the current direction carries
 * the anchor with it, so the reversal that follows is measured from where the run ended.
 */
export function nextDirection(state: ScrollState, y: number, { threshold, minDownY }: ScrollThresholds): ScrollState {
    // The top of the page is not a direction: nothing is scrolled away yet, so chrome shows in full.
    if (y <= 0) {
        return { direction: 'up', anchorY: 0 };
    }

    if (state.direction === 'down') {
        if (y >= state.anchorY) {
            return { direction: 'down', anchorY: y };
        }

        return state.anchorY - y >= threshold ? { direction: 'up', anchorY: y } : state;
    }

    if (y <= state.anchorY) {
        return { direction: 'up', anchorY: y };
    }

    return y >= minDownY && y - state.anchorY >= threshold ? { direction: 'down', anchorY: y } : state;
}

/** A direction and the page it was decided on. */
export interface PageDirection {
    direction: ScrollDirection;
    url: string;
}

/**
 * What a render shows. Inertia keeps the document across a navigation, so the direction the previous
 * page ended on is still in state while the next one first paints — the re-arm is derived here rather
 * than left to the effect, which runs a frame too late and lets that page paint with chrome already
 * gone. A disabled caller has no direction at all.
 */
export function renderedDirection(state: PageDirection, url: string, enabled: boolean): ScrollDirection {
    return enabled && state.url === url ? state.direction : 'up';
}

export interface ScrollDirectionOptions extends Partial<ScrollThresholds> {
    /** False attaches no listener: chrome that stays put should not pay for the events. */
    enabled?: boolean;
}

/** Which way the reader is going, for chrome that recedes while they read. */
export function useScrollDirection({
    threshold = 8,
    // The mobile bar's own height (2.75rem) — see ScrollThresholds.minDownY.
    minDownY = 44,
    enabled = true,
}: ScrollDirectionOptions = {}): ScrollDirection {
    const { url } = usePage();
    const [state, setState] = useState<PageDirection>({ direction: 'up', url });

    useEffect(() => {
        if (!enabled) {
            return;
        }

        const arm = (direction: ScrollDirection) =>
            setState((prev) => (prev.direction === direction && prev.url === url ? prev : { direction, url }));

        arm('up');
        let scroll: ScrollState = { direction: 'up', anchorY: window.scrollY };
        let frame = 0;

        // One position read per frame: scroll fires far more often than the browser paints, and
        // reading scrollY forces layout.
        const onScroll = () => {
            if (frame !== 0) {
                return;
            }
            frame = requestAnimationFrame(() => {
                frame = 0;
                scroll = nextDirection(scroll, window.scrollY, { threshold, minDownY });
                arm(scroll.direction);
            });
        };

        window.addEventListener('scroll', onScroll, { passive: true });

        return () => {
            if (frame !== 0) {
                cancelAnimationFrame(frame);
            }
            window.removeEventListener('scroll', onScroll);
        };
    }, [url, threshold, minDownY, enabled]);

    return renderedDirection(state, url, enabled);
}
