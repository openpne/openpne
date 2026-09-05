import { useEffect, useRef, type DOMAttributes } from 'react';

/** How long a finger stays down before the press is a press rather than a tap. */
const LONG_PRESS_MS = 500;
/**
 * The browser's own `pointercancel` normally ends a press that became a scroll, since native
 * scrolling is left alone; this is the backstop for a finger that drifts without scrolling anything.
 */
const MOVE_SLOP_PX = 10;
/**
 * How long after firing the click a released finger synthesises can still arrive. Bounded rather than
 * held until the next press: on a device with both, a real cursor click has to keep working.
 */
const SYNTHETIC_CLICK_MS = 700;

/**
 * A press, from the moment a finger lands to the moment it means something. `fired` is kept apart
 * from `idle` because what happens after the press has landed is different from what happens after
 * nothing did: the native menu stays suppressed, and the click the release synthesises is swallowed.
 */
export interface PressState {
    phase: 'idle' | 'pending' | 'fired';
    /** Where the finger went down — travel is measured from the press, not from the last event. */
    x: number;
    y: number;
}

export const IDLE: PressState = { phase: 'idle', x: 0, y: 0 };

export type PressEvent =
    | { type: 'down'; pointerType: string; x: number; y: number; primary: boolean }
    | { type: 'move'; x: number; y: number }
    | { type: 'up' }
    | { type: 'cancel' }
    | { type: 'timer' };

export interface PressResult {
    state: PressState;
    fire: boolean;
}

/**
 * A tap is not a press and never becomes one: nothing here consumes an event, so an ordinary tap
 * passes through. A cursor is excluded at the source, since a laptop with a touch screen answers
 * `pointer: fine` and still has fingers, and a second finger is the browser's `isPrimary`, answered
 * for the whole document.
 */
export function pressReducer(state: PressState, event: PressEvent): PressResult {
    switch (event.type) {
        case 'down':
            if (event.pointerType === 'mouse' || !event.primary) {
                return { state: IDLE, fire: false };
            }

            return { state: { phase: 'pending', x: event.x, y: event.y }, fire: false };

        case 'move': {
            if (state.phase !== 'pending') {
                return { state, fire: false };
            }
            const travelled = Math.hypot(event.x - state.x, event.y - state.y);

            return travelled >= MOVE_SLOP_PX ? { state: IDLE, fire: false } : { state, fire: false };
        }

        case 'up':
        case 'cancel':
            return { state: IDLE, fire: false };

        case 'timer':
            // Only a press still waiting can land, so the timer of a press already given up — or the
            // second timer of one already fired — says nothing.
            return state.phase === 'pending' ? { state: { ...state, phase: 'fired' }, fire: true } : { state, fire: false };
    }
}

/**
 * `enabled: false` attaches nothing at all, so an element with nothing to offer costs no listeners
 * and keeps its own context menu. Where it is enabled the menu is held off for the length of a press,
 * and the element's `user-select` / `-webkit-touch-callout` classes are the other half of that.
 */
export function useLongPress(onLongPress: () => void, { enabled = true }: { enabled?: boolean } = {}): DOMAttributes<HTMLElement> {
    const press = useRef<PressState>(IDLE);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const firedAt = useRef(0);

    useEffect(
        () => () => {
            if (timer.current !== null) {
                clearTimeout(timer.current);
            }
        },
        [],
    );

    if (!enabled) {
        return {};
    }

    const disarm = () => {
        if (timer.current !== null) {
            clearTimeout(timer.current);
            timer.current = null;
        }
    };

    const advance = (event: PressEvent) => {
        const next = pressReducer(press.current, event);
        press.current = next.state;
        if (next.state.phase !== 'pending') {
            disarm();
        }
        if (next.fire) {
            firedAt.current = performance.now();
            // Absent on iOS, where the press landing is felt through the sheet arriving instead.
            navigator.vibrate?.(10);
            onLongPress();
        }
    };

    return {
        onPointerDown: (e) => {
            advance({ type: 'down', pointerType: e.pointerType, x: e.clientX, y: e.clientY, primary: e.isPrimary });
            if (press.current.phase === 'pending') {
                timer.current = setTimeout(() => advance({ type: 'timer' }), LONG_PRESS_MS);
            }
        },
        onPointerMove: (e) => advance({ type: 'move', x: e.clientX, y: e.clientY }),
        onPointerUp: () => advance({ type: 'up' }),
        onPointerCancel: () => advance({ type: 'cancel' }),
        // Only while this element owns a press: a right-click anywhere keeps the browser's own menu,
        // which is the desktop lane's business and not this one's.
        onContextMenu: (e) => {
            if (press.current.phase !== 'idle') {
                e.preventDefault();
            }
        },
        // The release of a press that landed synthesises a click on whatever sits under the finger —
        // a link, a mention, a chip — and that click was never asked for.
        onClickCapture: (e) => {
            if (firedAt.current > 0 && performance.now() - firedAt.current < SYNTHETIC_CLICK_MS) {
                e.preventDefault();
                e.stopPropagation();
            }
        },
    };
}
