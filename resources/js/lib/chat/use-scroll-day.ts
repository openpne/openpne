import { useEffect, useRef, useState } from 'react';
import { rowAtLine } from './scroll-day.ts';

/** How long after the last scroll the indicator stays up, and how long it takes to go. */
const IDLE_MS = 500;

/** How long a fresh conversation is allowed to settle before a scroll counts as the reader's. */
const ARM_MS = 600;

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
export function useScrollDay(rowSelector: string, line: { current: HTMLElement | null }): { index: number | null; moving: boolean } {
    const [state, setState] = useState<{ index: number | null; moving: boolean }>({ index: null, moving: false });
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

            setState((current) => (current.index === index && current.moving ? current : { index, moving: true }));
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
