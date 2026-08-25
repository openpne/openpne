import { cloneElement, type ComponentProps, type ReactElement } from 'react';
import { Tooltip as TooltipPrimitive } from 'radix-ui';
import { cn } from '@/lib/utils';

export const TooltipProvider = TooltipPrimitive.Provider;
export const Tooltip = TooltipPrimitive.Root;
export const TooltipTrigger = TooltipPrimitive.Trigger;

/**
 * The label panel, in the house's floating-surface idiom (popover.tsx, dropdown-menu.tsx): portalled
 * so a scroll container or an `overflow-hidden` card between the trigger and the page cannot clip it,
 * and unanimated, like every other floating surface here. Only the padding is its own — a word or two
 * on one line, not a panel.
 */
export function TooltipContent({
    className,
    sideOffset = 6,
    collisionPadding = 8,
    ...props
}: ComponentProps<typeof TooltipPrimitive.Content>) {
    return (
        <TooltipPrimitive.Portal>
            <TooltipPrimitive.Content
                sideOffset={sideOffset}
                collisionPadding={collisionPadding}
                className={cn('z-50 rounded-xl border border-border bg-card px-2 py-1 text-xs shadow-lg', className)}
                {...props}
            />
        </TooltipPrimitive.Portal>
    );
}

/**
 * Names an icon-only control twice over: as its accessible name, and as the label a pointer or a
 * keyboard raises beside it. One argument, so the two can never say different things —
 * docs/internals/icon-labels.md.
 *
 * The contract, in three parts:
 *
 * - **Not a name source.** A tooltip never appears on a touch screen, so the control must be usable
 *   knowing only what it paints. `label` is what makes that true: it lands on the element as
 *   `aria-label` whether or not the panel is ever raised.
 * - **Icon-only controls only.** A control that already shows a word is named by that word; a second
 *   name spelled over it says something the reader cannot see (nav-drawer.tsx's labeled trigger).
 * - **Nothing while disabled.** A `disabled` control takes no pointer events and no focus, so no
 *   tooltip is raised on one. Known and accepted: what a disabled control needs said is why it cannot
 *   be pressed, which is a different thing from its name.
 *
 * The label is injected by cloning rather than passed down through `Trigger asChild`: Slot's merge
 * lets the child's own props win, so a child carrying `aria-label` would keep it and quietly disagree
 * with the panel — with the tooltip, the screenshots and the tests all still green. Cloning makes the
 * disagreement impossible, and a child that brought its own `aria-label` fails loudly in dev instead.
 * The guard reads the direct child's props and nothing deeper: an `aria-label` written inside a
 * component child is invisible to it, so this catches a mistake rather than proving there is none.
 *
 * `aria-describedby={undefined}` on the trigger drops the association Radix makes between the trigger
 * and the panel. Radix's own answer to the double announcement is the reverse — give `Content` an
 * `aria-label` and let the visible text stay a description — but that is for a trigger whose name
 * comes from somewhere else. Here the panel and the name are one string, so a description carrying it
 * again is "Reply, button, Reply". The child's own `aria-describedby` is untouched: it may be
 * pointing at an error or a hint that has nothing to do with this.
 */
export function Tip({
    label,
    silent = false,
    children,
}: {
    label: string;
    /**
     * The control is showing its word right now, so hold the panel back — the name is still spelled.
     * For a control that grows and collapses in place (action-fab): wrapping only the collapsed arm
     * would change the element type at that position, and React would rebuild the pill on every
     * toggle, so the label it animates would jump instead.
     */
    silent?: boolean;
    children: ReactElement<{ 'aria-label'?: string }>;
}) {
    // Before the clone, or the check would be reading the label this very call just injected.
    if (import.meta.env.DEV && children.props['aria-label'] !== undefined) {
        throw new Error(`<Tip label="${label}"> wraps a child that already has aria-label="${children.props['aria-label']}". Pass the name to Tip and remove it from the child.`);
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild aria-describedby={undefined}>
                {cloneElement(children, { 'aria-label': label })}
            </TooltipTrigger>
            {!silent && <TooltipContent>{label}</TooltipContent>}
        </Tooltip>
    );
}
