import { useCallback, useRef, useState } from 'react';
import type { SelectableText } from './select-text-sheet';

/**
 * Which row's sheet stands, held as the row's id and the row's element: the id is resolved against
 * the page's rows so a row deleted under the reader takes its sheet with it, and the element is
 * where focus returns. `selecting` is the body the sheet handed on, shown once the sheet has left.
 */
export function useRowSheet() {
    const [press, setPress] = useState<{ id: number; row: HTMLElement } | null>(null);
    const [selecting, setSelecting] = useState<{ text: SelectableText; row: HTMLElement } | null>(null);
    const pressed = useRef(press);
    pressed.current = press;

    const open = useCallback((id: number, row: HTMLElement) => setPress({ id, row }), []);
    const close = useCallback(() => setPress(null), []);
    const selectText = useCallback((text: SelectableText) => {
        const current = pressed.current;
        setPress(null);
        if (current !== null) {
            setSelecting({ text, row: current.row });
        }
    }, []);
    const closeSelect = useCallback(() => setSelecting(null), []);

    return { press, open, close, selecting, selectText, closeSelect };
}
