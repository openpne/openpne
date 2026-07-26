/**
 * The box chrome for the text-ish controls.
 *
 * `FIELD_BOX` is the boxed control every form field uses. `BARE_BOX` is the compose body's editing
 * surface: below sm it drops the box and pays `--frame-inset` on its own text instead, so the
 * tappable surface reaches both screen edges while the text lines up with the page heading — the
 * note/Gmail/x.com composer treatment. It is for the body only. Fields keep their box, because in
 * testing a borderless field was hard to recognise as an input at all, and the hairline meant to
 * separate rows read as the input's own border.
 *
 * These are whole-value swaps, not overrides: tailwind-merge does not know `rounded-field`, so
 * appending `rounded-none` to the boxed value would leave the winner to stylesheet order.
 */

/** text-base on mobile: a <16px control makes iOS Safari auto-zoom on focus, and the zoom persists
 * across Inertia's SPA navigations (page looks cut off on the right). */
const TYPE = 'text-base text-foreground md:text-sm';

const RING = 'focus-visible:border-ring focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';
const INVALID = 'aria-[invalid=true]:border-destructive aria-[invalid=true]:ring-2 aria-[invalid=true]:ring-destructive/30';
const DISABLED = 'disabled:cursor-not-allowed disabled:opacity-50';

export const FIELD_BOX = [
    'rounded-field border border-field-border bg-field px-3 py-2 shadow-sm transition-colors',
    TYPE,
    RING,
    INVALID,
    DISABLED,
].join(' ');

/**
 * No box and no focus ring below sm — the caret is the focus signal, as in every mobile composer we
 * looked at. Restores `FIELD_BOX` from sm up. The horizontal padding is supplied by the caller, which
 * knows whether it is paying the frame inset or the panel's own.
 */
export const BARE_BOX = [
    'border-0 bg-transparent p-0 shadow-none transition-colors focus-visible:outline-none',
    TYPE,
    DISABLED,
    'sm:rounded-field sm:border sm:border-field-border sm:bg-field sm:px-3 sm:py-2 sm:shadow-sm',
    'sm:focus-visible:border-ring sm:focus-visible:ring-2 sm:focus-visible:ring-ring',
    'sm:aria-[invalid=true]:border-destructive sm:aria-[invalid=true]:ring-2 sm:aria-[invalid=true]:ring-destructive/30',
].join(' ');
