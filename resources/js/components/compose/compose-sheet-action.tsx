import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

/**
 * The compose sheet's action slot: the mobile sheet header (top-nav.tsx) renders the slot, and the
 * page renders its submit button(s) into it from where the form lives. A portal rather than chrome
 * data, because the button carries page state (pending, disabled, label) React must keep updating.
 */

/**
 * The id every compose form carries, so an action outside it stays a real submitter
 * (`type="submit" form={COMPOSE_FORM_ID}`): the browser runs the form's constraint validation and
 * fires its submit event, where calling the page's handler from the header would skip both.
 */
export const COMPOSE_FORM_ID = 'compose-form';

interface Slot {
    element: HTMLElement | null;
    attach: (element: HTMLElement | null) => void;
}

const ComposeSlotContext = createContext<Slot | null>(null);

/** Holds the slot for the shell, so the header (producer) and the page (consumer) share one. */
export function ComposeSlotProvider({ children }: { children: ReactNode }) {
    // State, not a ref: the portal has to render again once the slot element exists, and it is set
    // from a ref callback rather than read off the document, so nothing touches the DOM at render.
    const [element, setElement] = useState<HTMLElement | null>(null);
    const attach = useCallback((node: HTMLElement | null) => setElement(node), []);
    const slot = useMemo(() => ({ element, attach }), [element, attach]);

    return <ComposeSlotContext value={slot}>{children}</ComposeSlotContext>;
}

const noop = () => {};

/** The ref the sheet header puts on its action slot. */
export function useComposeSlotRef(): (element: HTMLElement | null) => void {
    const slot = useContext(ComposeSlotContext);

    return slot?.attach ?? noop;
}

/** Renders the page's compose actions in the sheet header; nothing at all where there is no sheet. */
export function ComposeSheetAction({ children }: { children: ReactNode }) {
    const slot = useContext(ComposeSlotContext);

    return slot?.element ? createPortal(children, slot.element) : null;
}
