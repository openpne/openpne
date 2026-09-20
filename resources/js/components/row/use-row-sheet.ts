import { useCallback, useRef, useState } from 'react';

/**
 * Which row's sheet stands, held as the row's id and the row's element: the id is resolved against
 * the page's rows so a row deleted under the reader takes its sheet with it, and the element is
 * where focus returns. `selecting` is the body the sheet handed on, shown once the sheet has left.
 */
export function useRowSheet() {
    const [press, setPress] = useState<{ id: number; row: HTMLElement } | null>(null);
    const [selecting, setSelecting] = useState<{ body: string; row: HTMLElement } | null>(null);
    const pressed = useRef(press);
    pressed.current = press;

    const open = useCallback((id: number, row: HTMLElement) => setPress({ id, row }), []);
    const close = useCallback(() => setPress(null), []);
    const selectText = useCallback((body: string) => {
        const current = pressed.current;
        setPress(null);
        if (current !== null) {
            setSelecting({ body, row: current.row });
        }
    }, []);
    const closeSelect = useCallback(() => setSelecting(null), []);

    return { press, open, close, selecting, selectText, closeSelect };
}
