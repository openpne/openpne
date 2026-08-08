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
/**
 * Backstop for the exit, which normally ends on the stage's transitionend. Comfortably longer than
 * the transition in app.css so it only ever fires when that event is lost.
 */
const EXIT_FALLBACK_MS = 600;
/**
 * How long after a drag the click it synthesises can still arrive. Bounded rather than held until
 * the next touch: on a device with both, a real cursor click has to keep working.
 */
const SYNTHETIC_CLICK_MS = 700;

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

/**
 * How much of a drag counts, once the direction it committed to is taken into account.
 *
 * A sideways dismiss is the paging gesture run off the end of the deck, so it only means anything
 * in the direction it left by: at the first image you leave to the right, and dragging back left
 * from there is heading for an image, not for the exit. Clamping at the origin makes that dead
 * travel — the picture holds still — rather than a second exit in the wrong direction.
 *
 * A vertical dismiss has no such direction: up and down are both ways out, so `outward` is null and
 * the whole drag counts.
 */
export function outwardDisplacement(displacement: number, outward: number | null): number {
    if (outward === null) {
        return displacement;
    }

    const travelled = Math.max(0, displacement * outward);

    return travelled === 0 ? 0 : travelled * outward;
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
    '--lb-stage-opacity': '1',
    '--lb-scrim-opacity': '1',
    '--lb-chrome-opacity': '1',
};

export interface Rect {
    x: number;
    y: number;
    width: number;
    height: number;
}

/**
 * The transform that lands `from` exactly on `to`, expressed about the centre of `stage` — the
 * element that will carry it, whose own box is the origin its transform pivots on.
 *
 * Scale takes whichever axis has to shrink further, so the picture ends up inside its thumbnail
 * rather than spilling past it: the thumbnail is a square crop of an image that is not square.
 */
export function flightTo(from: Rect, to: Rect, stage: Rect): { x: number; y: number; scale: number } {
    if (from.width <= 0 || from.height <= 0 || to.width <= 0 || to.height <= 0) {
        return { x: 0, y: 0, scale: 1 };
    }

    const scale = Math.min(to.width / from.width, to.height / from.height);
    const cx = stage.x + stage.width / 2;
    const cy = stage.y + stage.height / 2;
    const fromCx = from.x + from.width / 2;
    const fromCy = from.y + from.height / 2;
    const toCx = to.x + to.width / 2;
    const toCy = to.y + to.height / 2;

    return {
        x: round(toCx - cx - scale * (fromCx - cx)),
        y: round(toCy - cy - scale * (fromCy - cy)),
        scale: round(scale),
    };
}

/** Where the picture goes when there is no thumbnail to go back to: on out the way it was pushed. */
export function flightOut(axis: DragAxis, displacement: number, extent: number): { x: number; y: number; scale: number } {
    const travel = (displacement < 0 ? -1 : 1) * extent * 2;

    return { x: axis === 'x' ? travel : 0, y: axis === 'y' ? travel : 0, scale: 1 };
}

export function flightVars(flight: { x: number; y: number; scale: number }): Record<string, string> {
    return {
        ...IDENTITY_VARS,
        '--lb-dismiss-x': `${flight.x}px`,
        '--lb-dismiss-y': `${flight.y}px`,
        '--lb-scale': String(flight.scale),
        '--lb-stage-opacity': '0',
        '--lb-scrim-opacity': '0',
        '--lb-chrome-opacity': '0',
    };
}

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
    /** The element whose transform the exit rides out on. */
    stageRef: RefObject<HTMLDivElement | null>;
    handlers: Pick<DOMAttributes<HTMLElement>, 'onTouchStart' | 'onTouchMove' | 'onTouchEnd' | 'onTouchCancel'>;
    /** Whether the click about to be handled is the one a finger's drag left behind. */
    draggedRecently: () => boolean;
}

interface Gesture {
    startX: number;
    startY: number;
    width: number;
    height: number;
    axis: DragAxis | null;
    mode: GestureMode | null;
    /** Sign of the direction a sideways dismiss left the deck by; null when there isn't one. */
    outward: number | null;
    /** Where the picture sat before this gesture moved it — the start of the flight home. */
    imageRect: Rect | null;
    /** The stage's own box, which its transform pivots on. */
    stageRect: Rect | null;
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
    open,
    index,
    count,
    onNavigate,
    onClose,
    originRect,
}: {
    open: boolean;
    index: number;
    count: number;
    onNavigate: (index: number) => void;
    onClose: () => void;
    /** Where the shown image lives on the page underneath, if it lives anywhere. */
    originRect?: (index: number) => Rect | null;
}): SwipeDeck {
    const contentRef = useRef<HTMLDivElement | null>(null);
    const scrimRef = useRef<HTMLDivElement | null>(null);
    const stageRef = useRef<HTMLDivElement | null>(null);
    const gesture = useRef<Gesture | null>(null);
    const draggedAt = useRef(0);
    const exiting = useRef(false);
    const endExit = useRef<(() => void) | null>(null);
    const endLeaving = useRef<(() => void) | null>(null);
    const leavingToken = useRef<object | null>(null);

    const write = (vars: Record<string, string>, { dragging = false, leaving = false } = {}) => {
        for (const el of [contentRef.current, scrimRef.current]) {
            if (!el) {
                continue;
            }
            el.toggleAttribute('data-lb-dragging', dragging);
            // Held through the flight as well as the drag: what leaves is one picture, so the deck
            // it belongs to has to stay out of sight the whole way.
            el.toggleAttribute('data-lb-leaving', leaving);
            for (const [name, value] of Object.entries(vars)) {
                el.style.setProperty(name, value);
            }
        }
    };

    const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /** Stop watching for a return to land. Says nothing about the flag — the caller owns that. */
    const stopLeavingWatch = () => {
        endLeaving.current?.();
        endLeaving.current = null;
        leavingToken.current = null;
    };

    /**
     * The deck stays out of sight until the stage has actually finished travelling, not merely been
     * told where to go. Dropping the flag with the write would uncover the neighbours while the stage
     * is still shrunk on its way back, which is the very thing hiding them was for.
     *
     * Only the newest watch may drop the flag. One gesture can settle twice — a pinch aborts it, then
     * the last finger lifting settles it again — and an older watch firing later would otherwise strip
     * the flag off whatever gesture owns it by then.
     */
    const releaseLeavingWhenSettled = () => {
        const stage = stageRef.current;
        const token = {};
        leavingToken.current = token;
        const drop = () => {
            if (leavingToken.current !== token) {
                return;
            }
            stopLeavingWatch();
            for (const el of [contentRef.current, scrimRef.current]) {
                el?.removeAttribute('data-lb-leaving');
            }
        };
        if (!stage || reducedMotion()) {
            drop();

            return;
        }
        const done = (e: TransitionEvent) => {
            if (e.target !== stage || e.propertyName !== 'transform') {
                return;
            }
            drop();
        };
        stage.addEventListener('transitionend', done);
        const timer = setTimeout(drop, EXIT_FALLBACK_MS);
        endLeaving.current = () => {
            stage.removeEventListener('transitionend', done);
            clearTimeout(timer);
        };
    };

    const settle = () => {
        const leaving = contentRef.current?.hasAttribute('data-lb-leaving') ?? false;
        // Cancel before re-arming, and re-assert the flag in the same write: a second settle would
        // otherwise leave the first watch running with nothing holding its reference.
        stopLeavingWatch();
        write({ ...IDENTITY_VARS }, { leaving });
        if (leaving) {
            releaseLeavingWhenSettled();
        }
    };

    const activeImage = () => stageRef.current?.querySelector<HTMLImageElement>('[data-active] img') ?? null;

    // Whatever closed the viewer — the exit below, Escape, the close button — lands here, and it is
    // the only place the exit is torn down. Without it a close during the exit would leave its timer
    // armed, and a viewer reopened before that timer fired would be shut by the previous one.
    useEffect(() => {
        if (open) {
            return;
        }
        endExit.current?.();
        endExit.current = null;
        stopLeavingWatch();
        exiting.current = false;
        gesture.current = null;
        settle();
    });

    useEffect(
        () => () => {
            endExit.current?.();
            endExit.current = null;
            endLeaving.current?.();
            endLeaving.current = null;
            leavingToken.current = null;
        },
        [],
    );

    const abort = () => {
        if (gesture.current) {
            gesture.current.aborted = true;
        }
        settle();
    };

    const extentOf = (g: Gesture) => (g.axis === 'y' ? g.height : g.width) * DISMISS_EXTENT;

    const travelled = (g: Gesture, displacement: number) => outwardDisplacement(displacement, g.outward);

    const paint = (g: Gesture, displacement: number) => {
        if (g.mode === 'page') {
            write(pageVars(displacement), { dragging: true });

            return;
        }
        const effective = travelled(g, displacement);
        const visual = dismissVisual(dismissProgress(effective, extentOf(g)));
        write(dismissVars(g.axis!, effective, visual), { dragging: true, leaving: true });
    };

    /**
     * Carry the drag the rest of the way rather than cutting to nothing: the reader has been watching
     * the image shrink and the page come through, and an instant unmount throws that away at the
     * moment it resolves.
     *
     * Where it goes is the picture's own place on the page below, when it has one — the reader's eye
     * is already tracking the image, so putting it back where they will next find it costs them no
     * search. Off the nearest edge is the fallback for when there is no such place.
     *
     * The animation's own end is what closes; the timer is only there for when that event never
     * arrives, and reduced motion skips the wait entirely.
     */
    const exit = (g: Gesture, displacement: number) => {
        exiting.current = true;
        const origin = originRect?.(index) ?? null;
        const target = origin && origin.width > 0 && origin.height > 0 ? origin : null;
        const flight =
            target && g.imageRect && g.stageRect
                ? flightTo(g.imageRect, target, g.stageRect)
                : flightOut(g.axis!, displacement, extentOf(g));
        write(flightVars(flight), { leaving: true });

        const stage = stageRef.current;
        if (!stage || reducedMotion()) {
            onClose();

            return;
        }

        // The stage's own transform, not a descendant's bubbling up to it.
        const done = (e: TransitionEvent) => {
            if (e.target !== stage || e.propertyName !== 'transform') {
                return;
            }
            endExit.current?.();
            endExit.current = null;
            onClose();
        };
        stage.addEventListener('transitionend', done);
        const timer = setTimeout(() => {
            endExit.current?.();
            endExit.current = null;
            onClose();
        }, EXIT_FALLBACK_MS);
        endExit.current = () => {
            stage.removeEventListener('transitionend', done);
            clearTimeout(timer);
        };
    };

    const handlers: SwipeDeck['handlers'] = {
        onTouchStart: (e) => {
            if (exiting.current) {
                return;
            }
            if (e.touches.length !== 1) {
                abort();

                return;
            }
            if (gesture.current?.aborted) {
                return;
            }
            const point = e.touches[0]!;
            const content = contentRef.current;
            // A touch interrupts whatever is still moving — a snapback easing home, a page settling
            // into its slot — and takes the deck to that destination at once. Measuring first would
            // record a rect from the middle of that animation, and the picture would then fly home
            // from a place it was never at.
            stopLeavingWatch();
            write({ ...IDENTITY_VARS }, { dragging: true });
            gesture.current = {
                startX: point.clientX,
                startY: point.clientY,
                width: content?.clientWidth ?? 0,
                height: content?.clientHeight ?? 0,
                axis: null,
                mode: null,
                outward: null,
                imageRect: activeImage()?.getBoundingClientRect() ?? null,
                stageRect: stageRef.current?.getBoundingClientRect() ?? null,
                samples: [],
                aborted: false,
            };
        },

        onTouchMove: (e) => {
            const g = gesture.current;
            if (!g || g.aborted || exiting.current) {
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
                g.outward = g.mode === 'dismiss' && g.axis === 'x' ? Math.sign(dx) : null;
            }
            draggedAt.current = performance.now();
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
            if (!g || g.aborted || !g.axis || !g.mode || exiting.current) {
                settle();

                return;
            }
            draggedAt.current = performance.now();
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

            const effective = travelled(g, displacement);
            const extent = extentOf(g);
            if (!shouldDismiss(dismissProgress(effective, extent), velocity, effective)) {
                settle();

                return;
            }
            exit(g, effective);
        },

        onTouchCancel: () => {
            gesture.current = null;
            // A cancelled touch synthesises no click, so nothing needs suppressing afterwards.
            draggedAt.current = 0;
            settle();
        },
    };

    return {
        contentRef,
        scrimRef,
        stageRef,
        handlers,
        draggedRecently: () => draggedAt.current > 0 && performance.now() - draggedAt.current < SYNTHETIC_CLICK_MS,
    };
}
