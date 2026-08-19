import { useEffect, useRef, useState } from 'react';
import { rowAtLine } from './scroll-day.ts';

/** How long after the last scroll the indicator stays up, and how long it takes to go. */
const IDLE_MS = 500;

/** How long a fresh conversation is allowed to settle before a scroll counts as the reader's. */
const ARM_MS = 600;

/** The strip the indicator occupies: its own height and a little, measured down from the line. */
const BAND_PX = 44;

/** And a hair above it, so a heading is out of the way before the indicator returns. */
const EDGE_PX = 8;

/**
 * Which day the reader is in, while they are moving through the conversation.
 *
 * It answers only during a scroll and goes quiet half a second after one stops, which is the whole
 * design: the reader looking for something wants to know where they are, and the reader who has
 * stopped to read wants nothing over the words. Those are the values Telegram uses, read from its
 * source (worklog talk-message-list-survey §5.6) rather than guessed.
 *
 * `line` is the element the reading area begins under — the indicator's own wrapper — so the offset
 * lives in the class that positions it and not in a second copy here.
 *
 * The index it returns is an index into the caller's own list. That holds because the list renders one
 * row per message in order, so the nth matching element is the nth message; a list that filtered or
 * reordered would have to hand back element identity instead.
 */
export function useScrollDay(
    rowSelector: string,
    line: { current: HTMLElement | null },
): { index: number | null; moving: boolean; standingIn: boolean } {
    const [state, setState] = useState<{ index: number | null; moving: boolean; standingIn: boolean }>({
        index: null,
        moving: false,
        standingIn: false,
    });
    // The frame a measurement is already booked for, and the timer that ends the burst. Refs because
    // neither is anything to render — they only decide when the next render is worth asking for.
    const frame = useRef<number | null>(null);
    const idle = useRef<ReturnType<typeof setTimeout> | null>(null);
    // Opening a conversation scrolls it to the foot, and that scroll is the page's, not the reader's.
    // Answering it put the indicator over the words for the first second of every visit — the exact
    // state this exists to avoid. Nothing distinguishes a programmatic scroll from a real one, so what
    // is armed is a moment: the settling is over well inside this (Inertia's own scroll lands within
    // ~50ms of mount), and a reader who beats it loses one burst.
    const armed = useRef(false);

    useEffect(() => {
        const measure = () => {
            frame.current = null;
            const top = line.current?.getBoundingClientRect().bottom;
            if (top === undefined) {
                return;
            }

            // Queried per measurement rather than cached: it walks the document once, where measuring
            // every row's box would make the layout engine do it 350 times. The rectangles were the
            // cost, and rowAtLine reads nine of them.
            const rows = document.querySelectorAll(rowSelector);
            const index = rowAtLine(rows.length, (at) => rows[at]?.getBoundingClientRect().bottom ?? 0, top);

            // Whether there is anything to stand in for. This exists because a day's heading has
            // scrolled away; while that heading is still on the screen it is saying the same thing in
            // the place it belongs, and a second copy is at best noise — at the head of the list it is
            // the same date twice over, once on the rule and once floating above the load-older strip.
            //
            // The governing heading is the last one above the row, and there is one per day rather
            // than per row, so a walk over them costs nothing worth avoiding.
            const owner = index === null ? null : rows[index]?.getBoundingClientRect() ?? null;
            const headings = [...document.querySelectorAll('li:has(> [role="separator"] time)')].map((h) =>
                h.getBoundingClientRect(),
            );
            const governing = owner === null ? undefined : headings.filter((h) => h.top <= owner.top).pop();
            const onScreen = governing !== undefined && governing.bottom > top && governing.top < window.innerHeight;

            // And nothing underneath it. The heading arriving at the line belongs to the *next* day,
            // so the rule above says nothing about it — it is the previous day's heading that governs
            // the row at the line. Left alone, a floating `12日` covers an arriving `13日` and reads as
            // that heading saying the wrong date. Two moving parts, two rules: one for having a job,
            // one for not sitting on somebody.
            const covering = headings.some((h) => h.bottom > top - EDGE_PX && h.top < top + BAND_PX);
            const standingIn = !onScreen && !covering;

            setState((current) =>
                current.index === index && current.moving && current.standingIn === standingIn
                    ? current
                    : { index, moving: true, standingIn },
            );
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
            idle.current = setTimeout(() => setState((current) => ({ ...current, moving: false })), IDLE_MS);
        };

        const arm = setTimeout(() => {
            armed.current = true;
        }, ARM_MS);
        window.addEventListener('scroll', onScroll, { passive: true });

        return () => {
            window.removeEventListener('scroll', onScroll);
            clearTimeout(arm);
            // Cleared *and* forgotten. A pending frame whose id is left behind makes `onScroll`'s
            // `=== null` test false for the rest of the screen's life, and only `measure` sets it back
            // — which cannot run, because booking it is what the test guards. The indicator then holds
            // whatever day it computed last, forever. Same for the idle timer, whose only re-arm is a
            // scroll: cleared and remembered, `moving` stays true and the thing sits over the words.
            if (frame.current !== null) {
                cancelAnimationFrame(frame.current);
                frame.current = null;
            }
            if (idle.current !== null) {
                clearTimeout(idle.current);
                idle.current = null;
            }
        };
        // The list is not closed over — `measure` re-queries the document every time — so nothing
        // about it belongs here. It was in these deps once, which tore the effect down on every
        // arriving message and, with the refs above left set, was the way the whole thing died.
    }, [rowSelector, line]);

    return state;
}
