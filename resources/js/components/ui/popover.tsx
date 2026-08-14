import type { ComponentProps } from 'react';
import { Popover as PopoverPrimitive } from 'radix-ui';
import { cn } from '@/lib/utils';

export const Popover = PopoverPrimitive.Root;
export const PopoverTrigger = PopoverPrimitive.Trigger;

/**
 * A panel anchored to its trigger, for content that is not a menu of commands — a grid of toggles,
 * a small form. Radix supplies the dialog role, the focus that moves into the panel and back to the
 * trigger on Esc, and outside-click dismissal.
 *
 * Portalled, so a scroll container or an `overflow-hidden` card between the trigger and the page
 * cannot clip it. Give it an `aria-label`: a dialog with no name is one axe refuses.
 */
export function PopoverContent({
    className,
    sideOffset = 6,
    collisionPadding = 8,
    ...props
}: ComponentProps<typeof PopoverPrimitive.Content>) {
    return (
        <PopoverPrimitive.Portal>
            <PopoverPrimitive.Content
                sideOffset={sideOffset}
                collisionPadding={collisionPadding}
                className={cn('z-50 rounded-xl border border-border bg-card p-2 shadow-lg', className)}
                {...props}
            />
        </PopoverPrimitive.Portal>
    );
}
