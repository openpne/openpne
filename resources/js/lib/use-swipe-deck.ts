import { useEffect, useRef, type DOMAttributes, type RefObject } from 'react';

export type DragAxis = 'x' | 'y';

/**
 * What a drag turns out to mean. A horizontal drag with somewhere to go turns the page; anything
 * else — a vertical drag, or a horizontal one off either end of the deck — closes the viewer. The
 * second is the first carried past its limit, which is why it reads as one gesture with two
 * outcomes rather than two gestures.
 */
export type GestureMode = 'page' | 'dismiss';

/** A displacement along the locked axis and when it was seen. */
export interface Sample {
    s: number;
    t: number;
}

export interface DismissVisual {
    scale: number;
    scrimOpacity: number;
    chromeOpacity: number;
}

/** Travel before a drag is a drag rather than a shaky tap. */
const AXIS_SLOP = 10;
/** Fraction of the viewport a dismiss drag has to cross for the release to close. */
const DISMISS_EXTENT = 0.3;
const DISMISS_PROGRESS = 0.35;
const DISMISS_VELOCITY = 0.5;
const PAGE_VELOCITY = 0.4;
/** Below this a "flick" is noise from lifting a finger, whatever the speed says. */
const FLICK_MIN_PX = 16;
const PAGE_MIN_PX = 48;
const PAGE_MAX_PX = 120;
const PAGE_FRACTION = 0.2;
/** Long enough to read as leaving, short enough not to sit between the reader and the page. */
const EXIT_MS = 200;

/** 4 decimals: enough for a smooth ramp, few enough that equal inputs give byte-equal strings. */
const round = (n: number) => Math.round(n * 10000) / 10000;

/**
 * Which axis a drag has committed to, or null while it is still too small to tell. A diagonal
 * resolves to 'y': turning the page is the more disruptive reading, so it has to be asked for
 * clearly.
 */
export function lockAxis(dx: number, dy: number, slop: number = AXIS_SLOP): DragAxis | null {
    if (Math.abs(dx) < slop && Math.abs(dy) < slop) {
        return null;
    }

    return Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
}

/**
 * Decided once, when the axis locks, and then held for the rest of the gesture: a drag that changed
 * its mind halfway would swap the image out from under the finger.
 */
export function gestureMode(axis: DragAxis, dx: number, index: number, count: number): GestureMode {
    if (axis === 'y') {
        return 'dismiss';
    }

    const pastStart = dx > 0 && index === 0;
    const pastEnd = dx < 0 && index === count - 1;

    return pastStart || pastEnd ? 'dismiss' : 'page';
}

/** How far along the closing gesture is, 0 to 1. */
export function dismissProgress(displacement: number, extent: number): number {
    if (extent <= 0) {
        return 0;
    }

    return Math.min(1, Math.abs(displacement) / extent);
}

/**
 * The picture the reader gets while they drag: the image shrinks, the scrim thins until the page
 * behind shows through, and the viewer's own chrome gets out of the way. Together they say "let go
 * and this closes" before the letting go — which is the whole reason the gesture is discoverable.
 * Chrome leaves at three times the rate so it is gone early rather than lingering half-faded.
 */
export function dismissVisual(progress: number): DismissVisual {
    return {
        scale: round(1 - 0.2 * progress),
        scrimOpacity: round(1 - 0.6 * progress),
        chromeOpacity: round(Math.max(0, 1 - 3 * progress)),
    };
}

/** A release closes on distance covered, or on a flick that was still moving outward when it ended. */
export function shouldDismiss(progress: number, velocity: number, displacement: number): boolean {
    if (progress >= DISMISS_PROGRESS) {
        return true;
    }

    const outward = velocity !== 0 && Math.sign(velocity) === Math.sign(displacement);

    return outward && Math.abs(velocity) >= DISMISS_VELOCITY && Math.abs(displacement) >= FLICK_MIN_PX;
}

/**
 * Where a released page drag lands. Bounded — a deck does not wrap — and the distance it takes to
 * commit scales with the viewport, so the same 100px is a decisive swipe on a phone and a twitch on
 * a desktop.
 */
export function snapTarget({
    index,
    count,
    dx,
    velocity,
    width,
}: {
    index: number;
    count: number;
    dx: number;
    velocity: number;
    width: number;
}): number {
    const distance = Math.min(PAGE_MAX_PX, Math.max(PAGE_MIN_PX, width * PAGE_FRACTION));
    const outward = velocity !== 0 && Math.sign(velocity) === Math.sign(dx);
    const flicked = outward && Math.abs(velocity) >= PAGE_VELOCITY && Math.abs(dx) >= FLICK_MIN_PX;

    if (Math.abs(dx) < distance && !flicked) {
        return index;
    }

    return Math.min(count - 1, Math.max(0, dx < 0 ? index + 1 : index - 1));
}

/**
 * Speed in px/ms over the tail of the gesture. Only the tail: a finger that swept across and then
 * held still before letting go has decided not to throw, and averaging over the whole drag would
 * read that as a flick anyway.
 */
export function velocityFrom(samples: readonly Sample[], windowMs = 100): number {
    const last = samples[samples.length - 1];
    if (!last || samples.length < 2) {
        return 0;
    }

    let first = last;
    for (let i = samples.length - 2; i >= 0; i--) {
        const sample = samples[i]!;
        if (last.t - sample.t > windowMs) {
            break;
        }
        first = sample;
    }

    const dt = last.t - first.t;

    return dt > 0 ? (last.s - first.s) / dt : 0;
}

/** The rest position: what every settle, cancel and abort has to be able to get back to. */
export const IDENTITY_VARS: Readonly<Record<string, string>> = {
    '--lb-drag-x': '0px',
    '--lb-dismiss-x': '0px',
    '--lb-dismiss-y': '0px',
    '--lb-scale': '1',
    '--lb-scrim-opacity': '1',
    '--lb-chrome-opacity': '1',
};

export function pageVars(dx: number): Record<string, string> {
    return { ...IDENTITY_VARS, '--lb-drag-x': `${round(dx)}px` };
}

export function dismissVars(axis: DragAxis, displacement: number, visual: DismissVisual): Record<string, string> {
    return {
        ...IDENTITY_VARS,
        '--lb-dismiss-x': `${axis === 'x' ? round(displacement) : 0}px`,
        '--lb-dismiss-y': `${axis === 'y' ? round(displacement) : 0}px`,
        '--lb-scale': String(visual.scale),
        '--lb-scrim-opacity': String(visual.scrimOpacity),
        '--lb-chrome-opacity': String(visual.chromeOpacity),
    };
}

export interface SwipeDeck {
    /** The dialog content: the gesture writes its custom properties, descendants inherit them. */
    contentRef: RefObject<HTMLDivElement | null>;
    /** The scrim is a portal sibling, not a descendant, so it is written to separately. */
    scrimRef: RefObject<HTMLDivElement | null>;
    handlers: Pick<DOMAttributes<HTMLElement>, 'onTouchStart' | 'onTouchMove' | 'onTouchEnd' | 'onTouchCancel'>;
    /** Whether the gesture that just ended moved — the click it synthesises must not dismiss. */
    dragged: () => boolean;
}

interface Gesture {
    startX: number;
    startY: number;
    width: number;
    height: number;
    axis: DragAxis | null;
    mode: GestureMode | null;
    samples: Sample[];
    aborted: boolean;
}

/**
 * Finger-tracking paging and swipe-to-dismiss for the lightbox deck.
 *
 * Nothing here calls setState. React owns the resting position (the shown index) and this owns the
 * offset from it, as two separate CSS custom properties, so neither writes a DOM property the other
 * also writes — depending on React DOM to skip unchanged style keys would work today and is not a
 * contract. Staying out of React's render also means the live-region readout cannot fire on every
 * frame of a drag: it changes when the index does, and the index changes once, on release.
 *
 * Gesture ownership rests on `touch-action: pinch-zoom` alone: the browser keeps two-finger zoom and
 * hands over every one-finger pan. React registers touch listeners as passive, so preventDefault is
 * not available — and with that touch-action it is not needed.
 */
export function useSwipeDeck({
    index,
    count,
    onNavigate,
    onClose,
}: {
    index: number;
    count: number;
    onNavigate: (index: number) => void;
    onClose: () => void;
}): SwipeDeck {
    const contentRef = useRef<HTMLDivElement | null>(null);
    const scrimRef = useRef<HTMLDivElement | null>(null);
    const gesture = useRef<Gesture | null>(null);
    const draggedRef = useRef(false);
    const exitTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(
        () => () => {
            if (exitTimer.current !== null) {
                clearTimeout(exitTimer.current);
            }
        },
        [],
    );

    const write = (vars: Record<string, string>, dragging: boolean) => {
        for (const el of [contentRef.current, scrimRef.current]) {
            if (!el) {
                continue;
            }
            el.toggleAttribute('data-lb-dragging', dragging);
            for (const [name, value] of Object.entries(vars)) {
                el.style.setProperty(name, value);
            }
        }
    };

    const settle = () => write({ ...IDENTITY_VARS }, false);

    const abort = () => {
        if (gesture.current) {
            gesture.current.aborted = true;
        }
        settle();
    };

    const extentOf = (g: Gesture) => (g.axis === 'y' ? g.height : g.width) * DISMISS_EXTENT;

    const paint = (g: Gesture, displacement: number) => {
        if (g.mode === 'page') {
            write(pageVars(displacement), true);

            return;
        }
        const visual = dismissVisual(dismissProgress(displacement, extentOf(g)));
        write(dismissVars(g.axis!, displacement, visual), true);
    };

    const handlers: SwipeDeck['handlers'] = {
        onTouchStart: (e) => {
            draggedRef.current = false;
            if (e.touches.length !== 1) {
                abort();

                return;
            }
            if (gesture.current?.aborted) {
                return;
            }
            const point = e.touches[0]!;
            const content = contentRef.current;
            gesture.current = {
                startX: point.clientX,
                startY: point.clientY,
                width: content?.clientWidth ?? 0,
                height: content?.clientHeight ?? 0,
                axis: null,
                mode: null,
                samples: [],
                aborted: false,
            };
        },

        onTouchMove: (e) => {
            const g = gesture.current;
            if (!g || g.aborted) {
                return;
            }
            // A second finger means a pinch. Dropping the anchor is not enough: the stage, scrim and
            // chrome would stay wherever the drag left them for the whole zoom.
            if (e.touches.length !== 1) {
                abort();

                return;
            }
            const point = e.touches[0]!;
            const dx = point.clientX - g.startX;
            const dy = point.clientY - g.startY;
            if (!g.axis) {
                g.axis = lockAxis(dx, dy);
                if (!g.axis) {
                    return;
                }
                g.mode = gestureMode(g.axis, dx, index, count);
            }
            draggedRef.current = true;
            const displacement = g.axis === 'x' ? dx : dy;
            g.samples.push({ s: displacement, t: e.timeStamp });
            if (g.samples.length > 8) {
                g.samples.shift();
            }
            paint(g, displacement);
        },

        onTouchEnd: (e) => {
            // Wait for the last finger: a pinch releases one at a time.
            if (e.touches.length > 0) {
                return;
            }
            const g = gesture.current;
            gesture.current = null;
            if (!g || g.aborted || !g.axis || !g.mode) {
                settle();

                return;
            }
            const touch = e.changedTouches[0];
            // The release point, not the last move: the final stretch of a fast drag arrives only
            // here, and its timestamp is what tells a throw apart from a drag that stopped and held.
            const displacement = touch
                ? g.axis === 'x'
                    ? touch.clientX - g.startX
                    : touch.clientY - g.startY
                : (g.samples[g.samples.length - 1]?.s ?? 0);
            const velocity = velocityFrom([...g.samples, { s: displacement, t: e.timeStamp }]);

            if (g.mode === 'page') {
                const target = snapTarget({ index, count, dx: displacement, velocity, width: g.width });
                settle();
                if (target !== index) {
                    onNavigate(target);
                }

                return;
            }

            const extent = extentOf(g);
            if (!shouldDismiss(dismissProgress(displacement, extent), velocity, displacement)) {
                settle();

                return;
            }
            // Carry the drag the rest of the way rather than cutting to nothing: the reader has been
            // watching the image shrink and the page come through, and an instant unmount throws that
            // away at the moment it resolves.
            const sign = displacement < 0 ? -1 : 1;
            write(dismissVars(g.axis, sign * extent * 2, dismissVisual(1)), false);
            exitTimer.current = setTimeout(onClose, EXIT_MS);
        },

        onTouchCancel: () => {
            gesture.current = null;
            settle();
        },
    };

    return { contentRef, scrimRef, handlers, dragged: () => draggedRef.current };
}
