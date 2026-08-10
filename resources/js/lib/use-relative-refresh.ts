import { useEffect, useReducer } from 'react';
// Relative, not the `@/` alias, so `node --test` can load this module's dependency (see use-site-day.ts).
import { nextRelativeRefreshDelay } from './date.ts';

/**
 * Re-renders the caller when its relative text stops being true — at the next minute below an hour, the
 * next hour below a day.
 *
 * Per stamp rather than shared, because unlike the day boundary each stamp changes at its own moment.
 * Only the one boundary ahead is scheduled, so a list of twenty rows holds twenty pending timers and
 * fires each once, rather than everything waking on a common tick. Past a day the next change is a
 * site-day boundary, which the shared day clock already waits on, and this arms nothing.
 */
export function useRelativeRefresh(at: string): void {
    const [, refresh] = useReducer((generation: number) => generation + 1, 0);

    // No dependency array on purpose: the delay is only valid for the render that computed it, so the
    // timer is re-armed after each one (the same reason SyncLocaleWithServer re-subscribes per render).
    useEffect(() => {
        const delay = nextRelativeRefreshDelay(at, new Date());

        if (delay === null) {
            return;
        }

        // A boundary that has just passed would otherwise re-render in a tight loop.
        const timer = setTimeout(refresh, Math.max(1000, delay));

        return () => clearTimeout(timer);
    });
}
