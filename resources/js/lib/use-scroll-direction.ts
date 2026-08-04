import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export type ScrollDirection = 'up' | 'down';

/** A direction plus the y it was decided at — travel is measured from there, not from the last event. */
export interface ScrollState {
    direction: ScrollDirection;
    anchorY: number;
}

/**
 * The direction after the page reached `y`. A flip needs `threshold` of travel from the point the
 * current direction was decided at: a slow drag arrives as a run of 3px events that each fall short
 * on their own and must still add up to a flip. Travel that continues the current direction carries
 * the anchor with it, so the reversal that follows is measured from where the run ended.
 */
export function nextDirection(state: ScrollState, y: number, threshold: number): ScrollState {
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

    return y - state.anchorY >= threshold ? { direction: 'down', anchorY: y } : state;
}

/**
 * Which way the reader is going, for chrome that recedes while they read. Re-armed per page: Inertia
 * keeps the document across a navigation, so a direction left over from the previous page would
 * otherwise decide how the next one first renders.
 */
export function useScrollDirection(threshold = 8): ScrollDirection {
    const { url } = usePage();
    const [direction, setDirection] = useState<ScrollDirection>('up');

    useEffect(() => {
        setDirection('up');
        let state: ScrollState = { direction: 'up', anchorY: window.scrollY };
        let frame = 0;

        // One position read per frame: scroll fires far more often than the browser paints, and
        // reading scrollY forces layout.
        const onScroll = () => {
            if (frame !== 0) {
                return;
            }
            frame = requestAnimationFrame(() => {
                frame = 0;
                state = nextDirection(state, window.scrollY, threshold);
                setDirection(state.direction);
            });
        };

        window.addEventListener('scroll', onScroll, { passive: true });

        return () => {
            if (frame !== 0) {
                cancelAnimationFrame(frame);
            }
            window.removeEventListener('scroll', onScroll);
        };
    }, [url, threshold]);

    return direction;
}
