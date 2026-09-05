import { useLayoutEffect, useRef, type RefObject } from 'react';

/**
 * Hold a textarea at the height of its own text, so a chat bar rests one line tall and grows with
 * what is typed. The resting height and the ceiling stay CSS's (`min-h-*` / `max-h-*`); this only
 * ever writes the measured height, and gives it back the moment the field is empty.
 */
export function useAutoGrow(field: RefObject<HTMLTextAreaElement | null>, value: string, enabled = true): void {
    // Whether the previous render held text — the transition to empty is the moment below.
    const held = useRef(false);

    useLayoutEffect(() => {
        const element = field.current;
        if (!enabled || element === null) {
            return;
        }
        // An empty box stands at its CSS height: the placeholder renders into scrollHeight, so
        // measuring here would size the bar to a wrapped placeholder instead of one line.
        if (value === '') {
            element.style.height = '';
            // iOS WebKit intermittently keeps the placeholder's box at the cleared text's
            // width until something re-lays the field out, and the reflow read between the
            // writes is what stops them coalescing.
            if (held.current) {
                const placeholder = element.placeholder;
                element.placeholder = '';
                void element.offsetWidth;
                element.placeholder = placeholder;
            }
            held.current = false;

            return;
        }
        held.current = true;
        // Measured from nothing: an already-set height is a floor the content can never shrink under.
        element.style.height = 'auto';
        // The box is border-box, so scrollHeight — the padding box — is short by the two borders.
        element.style.height = `${element.scrollHeight + element.offsetHeight - element.clientHeight}px`;
    }, [enabled, field, value]);
}
