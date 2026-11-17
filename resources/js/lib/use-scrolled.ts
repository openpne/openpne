import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/** Whether the page has moved off its top. Overscroll (a negative y on iOS) is still the top. */
export function pastTop(y: number): boolean {
    return y > 0;
}

export interface PageScrolled {
    scrolled: boolean;
    url: string;
}

/**
 * Inertia keeps the document across a navigation, so the flag the previous page ended on is still in
 * state while the next one first paints; the reset is derived here rather than left to the effect,
 * which runs a frame too late.
 */
export function renderedScrolled(state: PageScrolled, url: string): boolean {
    return state.url === url && state.scrolled;
}

export function useScrolled(): boolean {
    const { url } = usePage();
    const [state, setState] = useState<PageScrolled>({ scrolled: false, url });

    useEffect(() => {
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
    }, [url]);

    return renderedScrolled(state, url);
}
