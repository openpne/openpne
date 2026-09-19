import { useEffect, useState } from 'react';

const COARSE_QUERY = '(pointer: coarse)';

function matches(): boolean {
    return typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia(COARSE_QUERY).matches;
}

/** Whether the primary pointer is a finger: false where the query cannot be asked, so a server render draws the cursor's overlays. */
export function useCoarsePointer(): boolean {
    const [coarse, setCoarse] = useState(matches);

    useEffect(() => {
        if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
            return;
        }
        const mql = window.matchMedia(COARSE_QUERY);
        const onChange = () => setCoarse(mql.matches);
        onChange();
        mql.addEventListener('change', onChange);

        return () => mql.removeEventListener('change', onChange);
    }, []);

    return coarse;
}
