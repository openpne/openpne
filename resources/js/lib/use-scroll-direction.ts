import { router, usePage } from '@inertiajs/react';
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

/** What the reader does to take the scroll: a touch, wheel, key or focus move. Until one, a scroll is not theirs. */
const READER_INPUT = ['touchstart', 'pointerdown', 'wheel', 'keydown', 'focusin'] as const;

/**
 * Which way the reader is going, for chrome that recedes while they read.
 *
 * Only the reader's own scrolling counts, and a page starts with none: a scroll that arrives before
 * they have touched the page is the browser's — on iOS a standalone PWA was seen to report, after
 * Inertia's scroll to the top, the position the previous page was left at, and the chrome read that
 * as the reader scrolling down and left. Answering it with another scroll to the top was tried and
 * withdrawn: a programmatic scroll on iOS leaves the fixed bars' hit-testing stale until the reader
 * scrolls again, so the bar stood and took no taps. So the bounce is let stand, and the chrome waits.
 *
 * A page begins at a URL change, and again at any arrival Inertia has scrolled to the top — the
 * active tab tapped again is a same-URL visit, which Inertia fires no `navigate` for and which
 * would otherwise carry the reader's engagement through its reset into the bounce. Only an arrival
 * at the top re-gates: a reload landing while the reader is down the page keeps their travel.
 */
export function useScrollDirection({
    threshold = 8,
    // The shortest mobile bar's height (3rem); a taller bar (the labelled tabs' 58px) starts
    // hiding those few px early, harmlessly — see ScrollThresholds.minDownY.
    minDownY = 48,
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

        let scroll: ScrollState = { direction: 'up', anchorY: 0 };
        let frame = 0;
        let engaged = false;

        // The anchor is taken at the first input rather than at the gate, so a scroll the browser
        // made in between (the bounce above, a restore) is where the reader's travel is measured from.
        const engage = () => {
            engaged = true;
            scroll = { direction: 'up', anchorY: window.scrollY };
            READER_INPUT.forEach((type) => window.removeEventListener(type, engage, { capture: true }));
        };

        // Under the gate: nothing is the reader's yet, and the chrome shows in full. A read the
        // reader's last scroll had booked would otherwise land after the gate and take the bounce.
        const gate = () => {
            if (frame !== 0) {
                cancelAnimationFrame(frame);
                frame = 0;
            }
            engaged = false;
            arm('up');
            READER_INPUT.forEach((type) => window.addEventListener(type, engage, { capture: true, passive: true }));
        };

        // Fired once the page is set and scrolled. At the top the direction is 'up' whatever the
        // state, so re-gating there changes nothing that shows.
        const arrived = () => {
            if (window.scrollY <= 0) {
                gate();
            }
        };

        // One position read per frame: scroll fires far more often than the browser paints, and
        // reading scrollY forces layout.
        const onScroll = () => {
            if (!engaged || frame !== 0) {
                return;
            }
            frame = requestAnimationFrame(() => {
                frame = 0;
                scroll = nextDirection(scroll, window.scrollY, { threshold, minDownY });
                arm(scroll.direction);
            });
        };

        gate();
        window.addEventListener('scroll', onScroll, { passive: true });
        const offSuccess = router.on('success', arrived);
        const offError = router.on('error', arrived);

        return () => {
            if (frame !== 0) {
                cancelAnimationFrame(frame);
            }
            READER_INPUT.forEach((type) => window.removeEventListener(type, engage, { capture: true }));
            window.removeEventListener('scroll', onScroll);
            offSuccess();
            offError();
        };
    }, [url, threshold, minDownY, enabled]);

    return renderedDirection(state, url, enabled);
}
