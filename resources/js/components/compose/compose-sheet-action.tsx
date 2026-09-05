import { usePage } from '@inertiajs/react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
    type AnimationEvent,
    type ReactNode,
    type RefObject,
} from 'react';
import { createPortal } from 'react-dom';

/**
 * The header's action slot is a portal rather than chrome data, because the button carries page
 * state React must keep updating.
 */

/**
 * A form that submits natively pairs this with `type="submit" form={COMPOSE_FORM_ID}` on its header
 * action, so the browser still runs the form's constraint validation and fires its submit event.
 */
export const COMPOSE_FORM_ID = 'compose-form';

/** Runs `navigate` once the sheet has slid away, or straight away where it does not slide. */
export type ComposeExit = (navigate: () => void) => void;

interface Sheet {
    element: HTMLElement | null;
    attach: (element: HTMLElement | null) => void;
    exit: ComposeExit;
    setComposerEngaged: (engaged: boolean) => void;
}

const ComposeSheetContext = createContext<Sheet | null>(null);

/** The exit animation's keyframes; the shell lands the navigation when this one ends. */
export const SHEET_EXIT_ANIMATION = 'modern-sheet-out';

/** Longer than the animation, so a lost `animationend` still lets the reader leave. */
const EXIT_FALLBACK_MS = 450;

/**
 * A canceled visit or a popstate that never comes would otherwise leave an inert column held off the
 * bottom of the screen forever. On a slow network the restore may race a still-inflight arrival,
 * which costs a re-played entry.
 */
const EXIT_RESTORE_MS = 2000;

const immediate: ComposeExit = (navigate) => navigate();

export interface ComposeExitState {
    /** The column is sliding away: it holds the exit animation and takes no more input. */
    exiting: boolean;
    exit: ComposeExit;
    /** Belongs on the animated column — the exit lands when its animation ends. */
    onAnimationEnd: (event: AnimationEvent<HTMLElement>) => void;
}

/**
 * Only a sliding sheet waits: anywhere the slide does not happen the navigation is immediate, so no
 * path can strand the reader on a screen that never leaves.
 */
export function useComposeExitState(compose: boolean): ComposeExitState {
    const { url } = usePage();
    const [state, setState] = useState({ exiting: false, url });
    // Also the in-flight guard: a second close during the slide is the same exit, not another one.
    const pending = useRef<(() => void) | null>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const restore = useRef<ReturnType<typeof setTimeout> | null>(null);

    const clearTimers = useCallback(() => {
        if (timer.current !== null) {
            clearTimeout(timer.current);
            timer.current = null;
        }
        if (restore.current !== null) {
            clearTimeout(restore.current);
            restore.current = null;
        }
    }, []);

    const land = useCallback(() => {
        if (timer.current !== null) {
            clearTimeout(timer.current);
            timer.current = null;
        }
        const navigate = pending.current;
        pending.current = null;
        if (navigate === null) {
            return;
        }
        navigate();
        restore.current = setTimeout(() => {
            restore.current = null;
            setState((s) => (s.exiting ? { exiting: false, url: s.url } : s));
        }, EXIT_RESTORE_MS);
    }, []);

    // Every leftover goes with the URL: a stale timer must not fire a second navigation, and stale
    // `exiting` must not greet a revisit of the same URL as a surface already offscreen.
    useEffect(() => {
        if (state.url === url) {
            return;
        }
        clearTimers();
        pending.current = null;
        setState({ exiting: false, url });
    }, [url, state.url, clearTimers]);

    // Only the timers are cleaned up: an unmounted shell means the document is going away on its
    // own, and running a navigation into that is worse than dropping it.
    useEffect(() => clearTimers, [clearTimers]);

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

/**
 * The engagement setter comes from the shell for the same reason `exit` does: the shell cannot read
 * its own context.
 */
export function ComposeSheetProvider({
    exit,
    onComposerEngaged,
    children,
}: {
    exit: ComposeExit;
    onComposerEngaged: (engaged: boolean) => void;
    children: ReactNode;
}) {
    // State, not a ref: the portal has to render again once the slot element exists, and it is set
    // from a ref callback rather than read off the document, so nothing touches the DOM at render.
    const [element, setElement] = useState<HTMLElement | null>(null);
    const attach = useCallback((node: HTMLElement | null) => setElement(node), []);
    const sheet = useMemo(
        () => ({ element, attach, exit, setComposerEngaged: onComposerEngaged }),
        [element, attach, exit, onComposerEngaged],
    );

    return <ComposeSheetContext value={sheet}>{children}</ComposeSheetContext>;
}

const noop = () => {};

export function useComposeSlotRef(): (element: HTMLElement | null) => void {
    const sheet = useContext(ComposeSheetContext);

    return sheet?.attach ?? noop;
}

export function useComposeExit(): ComposeExit {
    return useContext(ComposeSheetContext)?.exit ?? immediate;
}

/**
 * Put the returned ref on the composer's form, not on the field: tapping send or attach moves focus
 * within the same form, and a field-level blur would take that for leaving. Unmounting clears the
 * flag, because the page after would have nothing to bring the bar back.
 */
export function useComposerEngaged(): RefObject<HTMLFormElement | null> {
    const form = useRef<HTMLFormElement>(null);
    const setEngaged = useContext(ComposeSheetContext)?.setComposerEngaged;

    useEffect(() => {
        const element = form.current;
        if (element === null || setEngaged === undefined) {
            return;
        }

        const engage = () => setEngaged(true);
        const release = (event: FocusEvent) => {
            if (!(event.relatedTarget instanceof Node) || !element.contains(event.relatedTarget)) {
                setEngaged(false);
            }
        };

        element.addEventListener('focusin', engage);
        element.addEventListener('focusout', release);

        return () => {
            element.removeEventListener('focusin', engage);
            element.removeEventListener('focusout', release);
            setEngaged(false);
        };
    }, [setEngaged]);

    return form;
}

export function ComposeSheetAction({ children }: { children: ReactNode }) {
    const sheet = useContext(ComposeSheetContext);

    return sheet?.element ? createPortal(children, sheet.element) : null;
}
