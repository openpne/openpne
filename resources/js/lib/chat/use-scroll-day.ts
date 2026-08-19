import { useEffect, useRef, useState } from 'react';
import { rowAtLine } from './scroll-day.ts';

/** How long after the last scroll the indicator stays up, and how long it takes to go. */
const IDLE_MS = 500;

/** How long a fresh conversation is allowed to settle before a scroll counts as the reader's. */
const ARM_MS = 600;

/** The strip the indicator occupies: its own height and a little, measured down from the line. */
const STRIP_PX = 44;

/** And a hair above it, so a heading is out of the way before the indicator returns. */
const STRIP_EDGE_PX = 8;

/**
 * How much further than the strip a heading has to be, on the side it is coming from.
 *
 * A wheel scroll is carried on the compositor and painted before the main thread is asked anything, so
 * this decision is always one frame behind the screen — and a frame is 260px at the speed a wheel
 * moves (measured, and it is the wheel's whole delta: one event, one frame). Judged against the strip
 * alone, a heading crosses the entire strip between two measurements and gets a date painted on it,
 * which is the flash that survived three rounds of fixing this. Judged against the distance the page
 * can travel in that frame, it is already gone before the heading arrives.
 *
 * Only on the approaching side. Slack behind the reader costs the indicator the moment it is worth
 * most — the heading has just left the top of the screen and nothing else says the day.
 */
const LAG_PX = 320;

/**
 * Which day the reader is in, while they are moving through the conversation.
 *
 * It answers only during a scroll and goes quiet half a second after one stops, which is the whole
 * design: the reader looking for something wants to know where they are, and the reader who has
 * stopped to read wants nothing over the words. Those are Telegram's values, read from its source
 * (worklog talk-message-list-survey §5.6) rather than guessed.
 *
 * **Whether it is drawn is written to the DOM here, in the frame it is decided, rather than returned
 * for React to render.** The indicator is sticky, so the browser carries it with the scroll at once,
 * while a decision routed through state arrives a render later — and one frame is all it takes to
 * paint a date on top of a heading. A per-frame recorder under a real wheel found exactly one
 * offending frame per run, which is that render. The day's *label* still comes back through React,
 * because a label a frame late is nothing.
 *
 * Three states, because the two ways down are not the same event. `up` is drawn. `idle` is the reader
 * having stopped, and fades. `aside` is something arriving underneath, and does not — politeness there
 * is a hundred and fifty milliseconds of exactly the overlap being avoided.
 *
 * `line` is the element the reading area begins under, which is also the element carrying the state:
 * the indicator's own wrapper, so the offset lives once in the class that positions it.
 *
 * The index it returns is an index into the caller's own list. That holds because the list renders one
 * row per message in order, so the nth matching element is the nth message.
 */
export function useScrollDay(
    rowSelector: string,
    line: { current: HTMLElement | null },
    suppressed: boolean,
    pinned: () => boolean,
): { index: number | null } {
    const [index, setIndex] = useState<number | null>(null);
    // The frame a measurement is booked for, the timer that ends the burst, and whether the page has
    // finished scrolling itself. Refs because none of them is anything to render.
    const frame = useRef<number | null>(null);
    const idle = useRef<ReturnType<typeof setTimeout> | null>(null);
    const armed = useRef(false);
    // Read inside a frame React did not schedule, so they have to live somewhere React is not.
    const off = useRef(suppressed);
    off.current = suppressed;
    // A predicate rather than a flag, because it is read in a frame React has not rendered yet. The
    // page keeps "is it at the foot" in state, updated from its own scroll handler — which means that
    // on the first scroll away from the foot the state still says foot, and a measurement booked from
    // that same event reads it. One press of PageUp is one event, so it stayed wrong until the next
    // one. Asked as a question instead, it is answered against the page as it is now.
    const foot = useRef(pinned);
    foot.current = pinned;
    // Where the page was last seen, and which way it has gone since.
    const last = useRef(0);
    const back = useRef(false);

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

            // Queried per measurement rather than cached: it walks the document once, where measuring
            // every row's box would make the layout engine do it 350 times. The rectangles were the
            // cost, and rowAtLine reads nine of them.
            const rows = document.querySelectorAll(rowSelector);
            const at = rowAtLine(rows.length, (n) => rows[n]?.getBoundingClientRect().bottom ?? 0, top);
            const owner = at === null ? null : (rows[at]?.getBoundingClientRect() ?? null);
            const headings = [...document.querySelectorAll('li:has(> [role="separator"] time)')].map((h) =>
                h.getBoundingClientRect(),
            );

            // Two ways there is nothing to do. The heading this copies may still be on screen, saying
            // the same thing where it belongs — at the head of the list that was the same date twice,
            // once on its rule and once floating over the load-older strip. Or a heading may be
            // arriving underneath: that one belongs to the *next* day, so the first test says nothing
            // about it, and left alone a floating `12日` sits on an arriving `13日` and reads as that
            // heading carrying the wrong date.
            const governing = owner === null ? undefined : headings.filter((h) => h.top <= owner.top).pop();
            const standingFor = governing !== undefined && !(governing.bottom > top && governing.top < window.innerHeight);
            // Which side to leave room on is the direction of travel: scrolling back through the
            // conversation brings headings *down* across the line, scrolling on brings them up.
            const above = back.current ? LAG_PX : STRIP_EDGE_PX;
            const below = back.current ? STRIP_PX : LAG_PX;
            const covering = headings.some((h) => h.bottom > top - above && h.top < top + below);

            show(!off.current && !foot.current() && standingFor && !covering ? 'up' : 'aside');
            setIndex(at);
        };

        const onScroll = () => {
            // Read here rather than in the measurement, and before the arming check. A frame coalesces
            // several of these, so the direction has to be taken from the events; and the page scrolls
            // itself to the foot on open, so a direction measured from where it started calls the
            // reader's first move the wrong way — and leaves it wrong, because nothing but another
            // scroll would correct it.
            const y = window.scrollY;
            back.current = y < last.current;
            last.current = y;

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

        last.current = window.scrollY;
        const arm = setTimeout(() => {
            armed.current = true;
        }, ARM_MS);
        window.addEventListener('scroll', onScroll, { passive: true });

        return () => {
            window.removeEventListener('scroll', onScroll);
            clearTimeout(arm);
            // Cleared *and* forgotten. A pending frame whose id is left behind makes the `=== null`
            // test above false for the rest of the screen's life, and only `measure` sets it back —
            // which cannot run, because booking it is what the test guards. Same for the idle timer,
            // whose only re-arm is a scroll.
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
