import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { PageProps } from '@/types';

const REDUCED_MOTION_QUERY = '(prefers-reduced-motion: reduce)';

function prefersReducedMotionNow(): boolean {
    return typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia(REDUCED_MOTION_QUERY).matches;
}

/** Read synchronously for the first render, so a reader who asked for less motion never sees a frame of it. */
export function usePrefersReducedMotion(): boolean {
    const [reduced, setReduced] = useState(prefersReducedMotionNow);

    useEffect(() => {
        if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
            return;
        }
        const mql = window.matchMedia(REDUCED_MOTION_QUERY);
        const onChange = () => setReduced(mql.matches);
        onChange();
        mql.addEventListener('change', onChange);
        return () => mql.removeEventListener('change', onChange);
    }, []);

    return reduced;
}

/** The OS preference wins over the member's switch (docs/internals/images.md, "Which placements animate"). */
export function useAnimatedPictures(): boolean {
    const { autoplayAnimations } = usePage<PageProps>().props;
    const reduced = usePrefersReducedMotion();

    return autoplayAnimations === true && !reduced;
}
