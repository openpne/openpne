import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/** Whether the page has moved off its top. Overscroll (a negative y on iOS) is still the top. */
export function pastTop(y: number): boolean {
    return y > 0;
}

/** A flag and the page it was decided on. */
export interface PageScrolled {
    scrolled: boolean;
    url: string;
}

/**
 * What a render shows. Inertia keeps the document across a navigation, so the flag the previous page
 * ended on is still in state while the next one first paints — the reset is derived here rather than
 * left to the effect, which runs a frame too late and would let a fresh page paint a seam it has not
 * earned. A disabled caller is never scrolled.
 */
export function renderedScrolled(state: PageScrolled, url: string, enabled: boolean): boolean {
    return enabled && state.url === url && state.scrolled;
}

export interface ScrolledOptions {
    /** False attaches no listener: chrome that always draws its seam should not pay for the events. */
    enabled?: boolean;
}

/** Whether content has scrolled under the top of the page, for chrome that only draws a seam over it. */
export function useScrolled({ enabled = true }: ScrolledOptions = {}): boolean {
    const { url } = usePage();
    const [state, setState] = useState<PageScrolled>({ scrolled: false, url });

    useEffect(() => {
        if (!enabled) {
            return;
        }

        const read = () =>
            setState((prev) => {
                const scrolled = pastTop(window.scrollY);

                return prev.scrolled === scrolled && prev.url === url ? prev : { scrolled, url };
            });

        // A restored scroll position is already off the top when the listener attaches.
        read();
        let frame = 0;

        // One position read per frame: scroll fires far more often than the browser paints, and
        // reading scrollY forces layout.
        const onScroll = () => {
            if (frame !== 0) {
                return;
            }
            frame = requestAnimationFrame(() => {
                frame = 0;
                read();
            });
        };

        window.addEventListener('scroll', onScroll, { passive: true });

        return () => {
            if (frame !== 0) {
                cancelAnimationFrame(frame);
            }
            window.removeEventListener('scroll', onScroll);
        };
    }, [url, enabled]);

    return renderedScrolled(state, url, enabled);
}
