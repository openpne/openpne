import { cloneElement, type ComponentProps, type FocusEvent, type ReactElement } from 'react';
import { Tooltip as TooltipPrimitive } from 'radix-ui';
import { cn } from '@/lib/utils';

export const TooltipProvider = TooltipPrimitive.Provider;
export const Tooltip = TooltipPrimitive.Root;
export const TooltipTrigger = TooltipPrimitive.Trigger;

/**
 * Portalled, so a scroll container or an `overflow-hidden` card between the trigger and the page
 * cannot clip it.
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

/** `preventDefault` is the flag Radix's composed handler reads before it opens on focus. */
function holdUnlessFocusVisible(event: FocusEvent<HTMLElement>) {
    if (!event.currentTarget.matches(':focus-visible')) {
        event.preventDefault();
    }
}

/**
 * Names an icon-only control twice over: as its accessible name, and as the panel raised beside it
 * (docs/internals/icon-labels.md, "Naming icon-only controls"). Layered on another Radix trigger both
 * roots write `data-state` and spread order decides which survives, so trigger styling keys on
 * `aria-expanded`.
 */
export function Tip({
    label,
    silent = false,
    children,
}: {
    label: string;
    /**
     * For a control that shows its word in one state and not the other: the name is injected, the
     * panel held back.
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
            <TooltipTrigger asChild aria-describedby={undefined} onFocus={holdUnlessFocusVisible}>
                {cloneElement(children, { 'aria-label': label })}
            </TooltipTrigger>
            {!silent && <TooltipContent>{label}</TooltipContent>}
        </Tooltip>
    );
}
