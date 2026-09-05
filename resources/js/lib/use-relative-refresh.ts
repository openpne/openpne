import { useEffect, useReducer } from 'react';

/**
 * A deadline computed during render, not a delay computed here, and a deadline already gone by when
 * the effect runs refreshes immediately rather than waiting (docs/internals/datetime.md, "Key
 * invariants"). `null` — a shape that is not relative, or one whose next change is a site-day
 * boundary — arms nothing.
 */
export function useRelativeRefresh(deadline: number | null): void {
    const [, refresh] = useReducer((generation: number) => generation + 1, 0);

    useEffect(() => {
        if (deadline === null) {
            return;
        }

        const delay = deadline - Date.now();

        if (delay <= 0) {
            refresh();

            return;
        }

        const timer = setTimeout(refresh, delay);

        return () => clearTimeout(timer);
    }, [deadline]);
}
