import { useEffect, useRef, useState } from 'react';
import { rowAtLine } from './scroll-day.ts';

/** How long after the last scroll the indicator stays up. */
const IDLE_MS = 500;

/** How long a fresh conversation is allowed to settle before a scroll counts as the reader's. */
const ARM_MS = 600;

/** The strip the indicator occupies, which is the room it needs when the page is not moving at all. */
const STRIP_PX = 44;

/**
 * Whether the indicator is drawn is written to the DOM in the frame it is decided, because a decision
 * routed through React state arrives a render later (docs/internals/group-talk.md, "Date headings,
 * and what stands above a row"). The index returned indexes the caller's own list, which holds only
 * because the list renders one row per message in order.
 */
export function useScrollDay(
    rowSelector: string,
    line: { current: HTMLElement | null },
    suppressed: boolean,
    pinned: () => boolean,
): { index: number | null } {
    const [index, setIndex] = useState<number | null>(null);
    // Refs because none of them is anything to render.
    const frame = useRef<number | null>(null);
    const idle = useRef<ReturnType<typeof setTimeout> | null>(null);
    const armed = useRef(false);
    // Read inside a frame React did not schedule, so they have to live somewhere React is not.
    const off = useRef(suppressed);
    off.current = suppressed;
    // A predicate rather than a flag: the page's own state still says foot on the first scroll away
    // from it, and a measurement booked from that same event would read the stale value.
    const foot = useRef(pinned);
    foot.current = pinned;
    // Where the page was when this last answered, which is the distance the answer is behind by.
    const seen = useRef(0);

    useEffect(() => {
        const wrapper = line.current;
        const show = (state: 'up' | 'idle' | 'aside') => {
            if (wrapper !== null) {
                wrapper.dataset.day = state;
            }
        };

        const measure = () => {
            frame.current = null;
            const top = wrapper?.getBoundingClientRect().bottom;
            if (top === undefined) {
                return;
            }

            // Queried per measurement rather than cached: the query walks the document once, while
            // the rectangles are the cost and rowAtLine reads nine of them.
            const rows = document.querySelectorAll(rowSelector);
            const at = rowAtLine(rows.length, (n) => rows[n]?.getBoundingClientRect().bottom ?? 0, top);
            const owner = at === null ? null : (rows[at]?.getBoundingClientRect() ?? null);
            const headings = [...document.querySelectorAll('[data-chat-day]')].map((h) => h.getBoundingClientRect());

            // The band is the distance the page moved since the last frame plus the strip, so the lag
            // is measured rather than guessed at (docs/internals/group-talk.md, "Date headings, and
            // what stands above a row").
            const y = window.scrollY;
            const reach = Math.abs(y - seen.current) + STRIP_PX;
            seen.current = y;

            // Two different questions: whether the heading this copies is still on screen, and
            // whether another is close enough to the strip to be overlapped by it.
            const governing = owner === null ? undefined : headings.filter((h) => h.top <= owner.top).pop();
            const standingFor = governing !== undefined && !(governing.bottom > top && governing.top < window.innerHeight);
            const covering = headings.some((h) => h.bottom > top - reach && h.top < top + reach);

            show(!off.current && !foot.current() && standingFor && !covering ? 'up' : 'aside');
            setIndex(at);
        };

        const onScroll = () => {
            if (!armed.current) {
                return;
            }

            if (frame.current === null) {
                frame.current = requestAnimationFrame(measure);
            }

            if (idle.current !== null) {
                clearTimeout(idle.current);
            }
            idle.current = setTimeout(() => show('idle'), IDLE_MS);
        };

        const arm = setTimeout(() => {
            armed.current = true;
            // Taken at arming, not at mount: the page scrolls itself to the foot in between, and a
            // distance measured from where it started would read as one enormous frame of travel.
            seen.current = window.scrollY;
        }, ARM_MS);
        window.addEventListener('scroll', onScroll, { passive: true });

        return () => {
            window.removeEventListener('scroll', onScroll);
            clearTimeout(arm);
            // Cleared and forgotten: a frame id left behind makes the `=== null` test above false for
            // good, since only `measure` clears it and booking it is what the test guards.
            if (frame.current !== null) {
                cancelAnimationFrame(frame.current);
                frame.current = null;
            }
            if (idle.current !== null) {
                clearTimeout(idle.current);
                idle.current = null;
            }
        };
        // The list is not closed over — `measure` re-queries the document every time — so nothing about
        // it belongs here, and both suppressions are read through refs for the same reason.
    }, [rowSelector, line]);

    return { index };
}
