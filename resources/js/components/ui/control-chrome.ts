/**
 * The box chrome shared by the text-ish controls (input / select / textarea).
 *
 * `FIELD_BOX` is the boxed control. `BARE_BOX` is the same control inside a `Panel bleed="full"` row:
 * below sm the box is gone (the row itself marks the field) and the control spends no padding of its
 * own, so its text sits on the row's `--frame-inset` and lines up with the page heading. From sm up
 * both resolve to the identical boxed control — bare is a below-sm treatment only.
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

/** The sm+ half both bare variants restore, so a wide screen sees no difference from `FIELD_BOX`. */
const SM_BOX = [
    'sm:rounded-field sm:border sm:border-field-border sm:bg-field sm:px-3 sm:py-2 sm:shadow-sm',
    'sm:focus-visible:border-ring sm:focus-visible:ring-2 sm:focus-visible:ring-ring',
].join(' ');

export const FIELD_BOX = [
    'rounded-field border border-field-border bg-field px-3 py-2 shadow-sm transition-colors',
    TYPE,
    RING,
    INVALID,
    DISABLED,
].join(' ');

/**
 * Bare with NO focus ring below sm — the caret is the focus signal, which is how note / Gmail /
 * x.com render their mobile composers. Only for controls that have a caret; anything else gets
 * {@link BARE_BOX_CARETLESS}.
 */
export const BARE_BOX = [
    'border-0 bg-transparent p-0 shadow-none transition-colors focus-visible:outline-none',
    TYPE,
    DISABLED,
    SM_BOX,
    'sm:aria-[invalid=true]:border-destructive sm:aria-[invalid=true]:ring-2 sm:aria-[invalid=true]:ring-destructive/30',
].join(' ');

/**
 * Bare for a control with no caret (select, date/time pickers): the box goes, but the focus ring
 * STAYS below sm — dropping both would leave no visible focus indicator at all (WCAG 2.4.7). The
 * radius and vertical padding are kept so the ring draws as a rounded band rather than clamping onto
 * the text. Decision #13 of the mobile width policy.
 */
export const BARE_BOX_CARETLESS = [
    'rounded-field border-0 bg-transparent px-0 py-2 shadow-none transition-colors',
    TYPE,
    RING,
    INVALID,
    DISABLED,
    'sm:border sm:border-field-border sm:bg-field sm:px-3 sm:shadow-sm',
].join(' ');

/** Input types that render no text caret, so they keep their focus ring when bare. */
const CARETLESS_TYPES = new Set(['date', 'datetime-local', 'month', 'week', 'time', 'color', 'file', 'range']);

/** Picks the bare variant for an `<input type>`; `undefined` (plain text) has a caret. */
export function bareBoxFor(type: string | undefined): string {
    return type !== undefined && CARETLESS_TYPES.has(type) ? BARE_BOX_CARETLESS : BARE_BOX;
}
