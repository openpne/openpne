import { useEffect, useReducer } from 'react';

/**
 * Re-renders the caller at `deadline` — the instant its relative text stops being true.
 *
 * A deadline computed during render, not a delay computed here: the two happen at different moments,
 * and a delay measured after commit would schedule the boundary *after* the one the text is already
 * past, leaving a stale bucket on screen until it fires. When the deadline has gone by before the
 * effect runs, that is exactly the case, and refreshing immediately is the fix rather than a wait.
 *
 * Per stamp rather than shared, because unlike the site's day boundary each stamp reaches its next
 * minute or hour at its own moment. Only the one boundary ahead is armed, so a twenty-row list holds
 * twenty pending timers and fires each once instead of everything waking on a common tick. `null` — a
 * shape that is not relative, or one whose next change is a site-day boundary — arms nothing.
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
