import { useCallback, useSyncExternalStore } from 'react';
// Relative, not the `@/` alias: `node --test` resolves neither the alias nor a module that uses it, so
// an aliased import here would make this module untestable (see the existing lib tests).
import { msUntilNextSiteDay } from './date.ts';

/**
 * One clock for the whole page, ticking when the site's calendar day changes.
 *
 * A list stamp is shaped relative to today, so without this a page left open past the site's midnight
 * keeps yesterday's rows showing a bare time, and one left open over New Year keeps last year's rows
 * without their year. One shared timer rather than one per stamp: every stamp on the page turns over
 * at the same instant, so there is only ever one boundary to wait for.
 *
 * Exported for its test; components use {@link useSiteDay}.
 */
export function createSiteDayClock() {
    const subscribers = new Set<() => void>();
    let generation = 0;
    let timeZone = 'UTC';
    let timer: ReturnType<typeof setTimeout> | undefined;

    const arm = () => {
        clearTimeout(timer);
        // Never below a second. The delay is an estimate — a DST shift inside the day moves the real
        // boundary — so a short wake is expected and harmless: it just re-arms from the new now. The
        // floor is what stops a mis-estimate from spinning.
        timer = setTimeout(tick, Math.max(1000, msUntilNextSiteDay(new Date(), timeZone)));
    };

    function tick() {
        generation += 1;
        for (const callback of subscribers) {
            callback();
        }
        arm();
    }

    // A background tab's timers are throttled or suspended, so the midnight wake can arrive late or
    // not at all. Re-reading the clock on return is what makes a tab left open overnight correct.
    const onVisible = () => {
        if (document.visibilityState === 'visible') {
            tick();
        }
    };

    return {
        subscribe(zone: string, callback: () => void): () => void {
            timeZone = zone;
            subscribers.add(callback);

            if (subscribers.size === 1) {
                arm();
                document?.addEventListener('visibilitychange', onVisible);
            }

            return () => {
                subscribers.delete(callback);

                if (subscribers.size === 0) {
                    clearTimeout(timer);
                    timer = undefined;
                    document?.removeEventListener('visibilitychange', onVisible);
                }
            };
        },
        /** A changing primitive for useSyncExternalStore; its value carries no meaning. */
        getSnapshot: () => generation,
    };
}

const clock = createSiteDayClock();

/**
 * Subscribes the caller to the site's day boundary. The return value is meaningless — taking it is how
 * a component re-renders when the day turns over.
 */
export function useSiteDay(timeZone: string): number {
    const subscribe = useCallback((callback: () => void) => clock.subscribe(timeZone, callback), [timeZone]);

    return useSyncExternalStore(subscribe, clock.getSnapshot, clock.getSnapshot);
}
