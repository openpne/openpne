import type { ComponentProps } from 'react';
import { Popover as PopoverPrimitive } from 'radix-ui';
import { cn } from '@/lib/utils';

export const Popover = PopoverPrimitive.Root;
export const PopoverTrigger = PopoverPrimitive.Trigger;
export const PopoverAnchor = PopoverPrimitive.Anchor;

const SHAPES = {
    card: 'rounded-xl border border-border bg-card p-2 shadow-lg',
    band: 'w-(--radix-popover-trigger-width) border-b border-border bg-background px-2 pt-1 pb-2 shadow-md',
};

/**
 * Portalled, so a scroll container or an `overflow-hidden` card between the trigger and the page
 * cannot clip it. Give it an `aria-label`: a dialog with no name is one axe refuses.
 */
export function PopoverContent({
    className,
    shape = 'card',
    fade = false,
    sideOffset = 6,
    collisionPadding = 8,
    ...props
}: ComponentProps<typeof PopoverPrimitive.Content> & { shape?: keyof typeof SHAPES; fade?: boolean }) {
    return (
        <PopoverPrimitive.Portal>
            <PopoverPrimitive.Content
                sideOffset={sideOffset}
                collisionPadding={collisionPadding}
                className={cn(
                    'z-50',
                    SHAPES[shape],
                    fade && 'motion-safe:data-[state=closed]:animate-fade-out motion-safe:data-[state=open]:animate-fade-in',
                    className,
                )}
                {...props}
            />
        </PopoverPrimitive.Portal>
    );
}
