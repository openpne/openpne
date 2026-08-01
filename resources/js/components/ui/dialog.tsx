import type { ComponentProps } from 'react';
import { Dialog as DialogPrimitive } from 'radix-ui';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';

export const Dialog = DialogPrimitive.Root;
export const DialogTrigger = DialogPrimitive.Trigger;
export const DialogClose = DialogPrimitive.Close;
export const DialogTitle = DialogPrimitive.Title;

/** Dialog content rendered as a left-edge sheet (the mobile nav drawer). Radix supplies the focus
 *  trap, ESC/overlay dismissal, and scroll lock. */
/**
 * Centered modal panel. Below sm it anchors to the upper area instead of vertical center so the
 * soft keyboard never covers the panel (the visual viewport shrinks from the bottom).
 */
export function DialogContent({
    className,
    children,
    closeLabel = 'Close',
    ...props
}: ComponentProps<typeof DialogPrimitive.Content> & { closeLabel?: string }) {
    return (
        <DialogPrimitive.Portal>
            <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm" />
            <DialogPrimitive.Content
                className={cn(
                    'fixed left-1/2 top-24 z-50 w-[calc(100vw-2rem)] max-w-md -translate-x-1/2 rounded-card border border-border bg-background p-4 shadow-xl outline-none sm:top-1/2 sm:-translate-y-1/2',
                    className,
                )}
                {...props}
            >
                {children}
                <DialogPrimitive.Close
                    className="absolute right-3 top-3 rounded-full p-1 text-muted-foreground transition hover:bg-accent"
                    aria-label={closeLabel}
                >
                    <X className="size-5" />
                </DialogPrimitive.Close>
            </DialogPrimitive.Content>
        </DialogPrimitive.Portal>
    );
}

export function SheetContent({
    className,
    children,
    closeLabel = 'Close',
    ...props
}: ComponentProps<typeof DialogPrimitive.Content> & { closeLabel?: string }) {
    return (
        <DialogPrimitive.Portal>
            <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm" />
            <DialogPrimitive.Content
                className={cn(
                    // Edge-to-edge by construction (inset-y-0, left-0), so it pads for all three insets
                    // it can meet: status bar, home indicator, and the landscape cutout on its left.
                    'fixed inset-y-0 left-0 z-50 flex w-80 max-w-[85vw] flex-col gap-1 bg-background p-4 pt-[calc(1rem+env(safe-area-inset-top))] pb-[calc(1rem+env(safe-area-inset-bottom))] pl-[calc(1rem+env(safe-area-inset-left))] shadow-xl outline-none',
                    className,
                )}
                {...props}
            >
                {children}
                {/* Absolutely positioned, so the sheet's top padding does not move it: inset it itself. */}
                <DialogPrimitive.Close
                    className="absolute right-3 top-[calc(0.75rem+env(safe-area-inset-top))] rounded-full p-1 text-muted-foreground transition hover:bg-accent"
                    aria-label={closeLabel}
                >
                    <X className="size-5" />
                </DialogPrimitive.Close>
            </DialogPrimitive.Content>
        </DialogPrimitive.Portal>
    );
}
