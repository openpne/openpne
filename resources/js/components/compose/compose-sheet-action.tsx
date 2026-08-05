import { usePage } from '@inertiajs/react';
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type AnimationEvent, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

/**
 * What the mobile compose sheet shares between the shell, its header and the page: the header's
 * action slot, and the exit the close control fires. The slot is a portal rather than chrome data,
 * because the button carries page state (pending, disabled, label) React must keep updating.
 */

/**
 * The id every compose form carries. A form that submits natively pairs it with
 * `type="submit" form={COMPOSE_FORM_ID}` on its header action, keeping it a real submitter: the
 * browser runs the form's constraint validation and fires its submit event, where calling the
 * page's handler from the header would skip both. The message forms post from their buttons'
 * own click handlers instead (they never had a native submit path), so there the id is only the
 * seam for switching later.
 */
export const COMPOSE_FORM_ID = 'compose-form';

/** Runs `navigate` once the sheet has slid away, or straight away where it does not slide. */
export type ComposeExit = (navigate: () => void) => void;

interface Sheet {
    element: HTMLElement | null;
    attach: (element: HTMLElement | null) => void;
    exit: ComposeExit;
}

const ComposeSheetContext = createContext<Sheet | null>(null);

/** The exit animation's keyframes; the shell lands the navigation when this one ends. */
export const SHEET_EXIT_ANIMATION = 'modern-sheet-out';

/** Longer than the animation, so a lost `animationend` still lets the reader leave. */
const EXIT_FALLBACK_MS = 450;

const immediate: ComposeExit = (navigate) => navigate();

export interface ComposeExitState {
    /** The column is sliding away: it holds the exit animation and takes no more input. */
    exiting: boolean;
    exit: ComposeExit;
    /** Belongs on the animated column — the exit lands when its animation ends. */
    onAnimationEnd: (event: AnimationEvent<HTMLElement>) => void;
}

/**
 * The sheet's exit, owned by the shell: the close control asks for it, the column plays it, and the
 * navigation it was given runs at the end. Only a sliding sheet waits — anywhere the slide does not
 * happen (a page that is not compose, lg and up, reduced motion) the navigation is immediate, so no
 * path can strand the reader on a screen that never leaves.
 */
export function useComposeExitState(compose: boolean): ComposeExitState {
    const { url } = usePage();
    const [state, setState] = useState({ exiting: false, url });
    // The navigation waiting on the animation. Also the in-flight guard: a second close during the
    // slide is the same exit, not another one.
    const pending = useRef<(() => void) | null>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const land = useCallback(() => {
        if (timer.current !== null) {
            clearTimeout(timer.current);
            timer.current = null;
        }
        const navigate = pending.current;
        pending.current = null;
        navigate?.();
    }, []);

    // Only the timer is cleaned up: an unmounted shell means the document is going away on its own,
    // and running a navigation into that is worse than dropping it.
    useEffect(
        () => () => {
            if (timer.current !== null) {
                clearTimeout(timer.current);
            }
        },
        [],
    );

    const exit = useCallback<ComposeExit>(
        (navigate) => {
            if (pending.current !== null) {
                return;
            }
            // The same three conditions the class does (`max-lg:motion-safe:` on a compose column),
            // asked of the platform rather than restated: 64rem is Tailwind's lg.
            const slides =
                compose &&
                !window.matchMedia('(min-width: 64rem)').matches &&
                !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            if (!slides) {
                navigate();

                return;
            }

            pending.current = navigate;
            timer.current = setTimeout(land, EXIT_FALLBACK_MS);
            setState({ exiting: true, url });
        },
        [compose, land, url],
    );

    const onAnimationEnd = useCallback(
        (event: AnimationEvent<HTMLElement>) => {
            if (event.animationName === SHEET_EXIT_ANIMATION) {
                land();
            }
        },
        [land],
    );

    // Derived, like the scroll hooks: the navigation the exit runs keeps the document, so a page
    // arriving after one must not paint from off the bottom of the screen.
    return { exiting: state.exiting && state.url === url, exit, onAnimationEnd };
}

/** Holds the sheet for the shell, so the header (producer) and the page (consumer) share one. */
export function ComposeSheetProvider({ exit, children }: { exit: ComposeExit; children: ReactNode }) {
    // State, not a ref: the portal has to render again once the slot element exists, and it is set
    // from a ref callback rather than read off the document, so nothing touches the DOM at render.
    const [element, setElement] = useState<HTMLElement | null>(null);
    const attach = useCallback((node: HTMLElement | null) => setElement(node), []);
    const sheet = useMemo(() => ({ element, attach, exit }), [element, attach, exit]);

    return <ComposeSheetContext value={sheet}>{children}</ComposeSheetContext>;
}

const noop = () => {};

/** The ref the sheet header puts on its action slot. */
export function useComposeSlotRef(): (element: HTMLElement | null) => void {
    const sheet = useContext(ComposeSheetContext);

    return sheet?.attach ?? noop;
}

/** How the sheet's close control leaves: play the exit, then navigate. */
export function useComposeExit(): ComposeExit {
    return useContext(ComposeSheetContext)?.exit ?? immediate;
}

/** Renders the page's compose actions in the sheet header; nothing at all where there is no sheet. */
export function ComposeSheetAction({ children }: { children: ReactNode }) {
    const sheet = useContext(ComposeSheetContext);

    return sheet?.element ? createPortal(children, sheet.element) : null;
}
