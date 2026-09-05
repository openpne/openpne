import type { ComponentProps } from 'react';
import { Dialog as DialogPrimitive } from 'radix-ui';
import { X } from 'lucide-react';
import { Tip } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

export const Dialog = DialogPrimitive.Root;
export const DialogTrigger = DialogPrimitive.Trigger;
export const DialogClose = DialogPrimitive.Close;
export const DialogTitle = DialogPrimitive.Title;

/**
 * Below sm it anchors near the top rather than the vertical center: the soft keyboard shrinks the
 * visual viewport from the bottom and would cover a centered panel.
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
                <Tip label={closeLabel}>
                    <DialogPrimitive.Close className="absolute right-3 top-3 rounded-full p-1 text-muted-foreground transition hover:bg-accent">
                        <X className="size-5" />
                    </DialogPrimitive.Close>
                </Tip>
            </DialogPrimitive.Content>
        </DialogPrimitive.Portal>
    );
}

/**
 * Each entry overrides the base sheet, which is written for a full-height side drawer. The bottom
 * one restates the top padding because it has no top edge to inset from.
 */
const SHEET_SIDE = {
    left: 'left-0 pl-[calc(1rem+env(safe-area-inset-left))]',
    right: 'right-0 w-full max-w-none pr-[calc(0.75rem+env(safe-area-inset-right))] pl-[calc(0.75rem+env(safe-area-inset-left))] motion-safe:data-[state=open]:animate-sheet-from-right motion-safe:data-[state=closed]:animate-sheet-to-right',
    bottom: 'inset-x-0 top-auto bottom-0 w-full max-w-none max-h-[70dvh] overflow-y-auto rounded-t-xl border-t border-border pt-4 pb-[calc(1rem+env(safe-area-inset-bottom))] motion-safe:data-[state=open]:animate-sheet-from-bottom',
};

/**
 * `side` is the screen edge the sheet hugs, and its close control sits on that same edge. Radix
 * keeps the content mounted while the closed animation runs, which is what the right sheet's exit
 * animation needs.
 */
export function SheetContent({
    className,
    children,
    closeLabel = 'Close',
    side = 'left',
    ...props
}: ComponentProps<typeof DialogPrimitive.Content> & { closeLabel?: string; side?: 'left' | 'right' | 'bottom' }) {
    return (
        <DialogPrimitive.Portal>
            <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm" />
            <DialogPrimitive.Content
                className={cn(
                    // Edge-to-edge by construction (inset-y-0), so it pads for all three insets it can
                    // meet: status bar, home indicator, and the landscape cutout on the edge it hugs.
                    'fixed inset-y-0 z-50 flex w-80 max-w-[85vw] flex-col gap-1 bg-background p-4 pt-[calc(1rem+env(safe-area-inset-top))] pb-[calc(1rem+env(safe-area-inset-bottom))] shadow-xl outline-none',
                    SHEET_SIDE[side],
                    className,
                )}
                {...props}
            >
                {/* First in the DOM as well as top-right on screen: a 48px labelled control that
                    read after the whole nav would put the tab order at odds with the visual one. */}
                {side === 'right' && (
                    // Wordful, so no Tip: the control is named by the word it shows.
                    <DialogPrimitive.Close className="absolute top-[calc(0.25rem+env(safe-area-inset-top))] right-[calc(0.5rem+env(safe-area-inset-right))] inline-flex size-12 flex-col items-center justify-center gap-0.5 rounded-full text-muted-foreground transition hover:bg-accent">
                        <X className="size-6" aria-hidden />
                        <span className="text-[11px] leading-none">{closeLabel}</span>
                    </DialogPrimitive.Close>
                )}
                {children}
                {/* Absolutely positioned, so the sheet's top padding does not move it: inset it itself. */}
                {side !== 'right' && (
                    <Tip label={closeLabel}>
                        <DialogPrimitive.Close
                            className={cn(
                                'absolute right-3 rounded-full p-1 text-muted-foreground transition hover:bg-accent',
                                // A sheet standing on the foot of the screen has no status bar over its top
                                // corner, so its close sits at the plain gutter.
                                side === 'bottom' ? 'top-3' : 'top-[calc(0.75rem+env(safe-area-inset-top))]',
                            )}
                        >
                            <X className="size-5" />
                        </DialogPrimitive.Close>
                    </Tip>
                )}
            </DialogPrimitive.Content>
        </DialogPrimitive.Portal>
    );
}
