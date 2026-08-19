import { useEffect, useRef, useState } from 'react';
import { rowAtLine } from './scroll-day.ts';

/** How long after the last scroll the indicator stays up, and how long it takes to go. */
const IDLE_MS = 500;

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
    count: number,
): { index: number | null; moving: boolean } {
    const [state, setState] = useState<{ index: number | null; moving: boolean }>({ index: null, moving: false });
    // The frame a measurement is already booked for, and the timer that ends the burst. Refs because
    // neither is anything to render — they only decide when the next render is worth asking for.
    const frame = useRef<number | null>(null);
    const idle = useRef<ReturnType<typeof setTimeout> | null>(null);

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
            if (frame.current === null) {
                frame.current = requestAnimationFrame(measure);
            }

            if (idle.current !== null) {
                clearTimeout(idle.current);
            }
            idle.current = setTimeout(() => setState((current) => ({ ...current, moving: false })), IDLE_MS);
        };

        window.addEventListener('scroll', onScroll, { passive: true });

        return () => {
            window.removeEventListener('scroll', onScroll);
            if (frame.current !== null) {
                cancelAnimationFrame(frame.current);
            }
            if (idle.current !== null) {
                clearTimeout(idle.current);
            }
        };
        // `count` re-arms nothing by itself; it is here so a list that grew under a resting reader is
        // measured against on their next move rather than against the list this effect closed over.
    }, [rowSelector, line, count]);

    return state;
}
