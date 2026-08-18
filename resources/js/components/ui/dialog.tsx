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

/**
 * `side` is the screen edge the sheet hugs. A drawer opens from the side its trigger stands on — the
 * one thing every site in the 2026-08 menu survey agreed about — so a bar that moves its hamburger
 * moves the sheet with it. The close control belongs to the trigger's side too.
 *
 * The right sheet is the tabbed look's: full-bleed, sliding from its edge (Radix holds it mounted
 * while the closed animation runs), padded to the breadcrumb bar's gutters so what the drawer and
 * the bar both draw — the brand, the menu control — lands in the same place open or shut. Its close
 * control is the trigger's twin: same box, same spot, the word "close" where "menu" stood.
 */
export function SheetContent({
    className,
    children,
    closeLabel = 'Close',
    side = 'left',
    ...props
}: ComponentProps<typeof DialogPrimitive.Content> & { closeLabel?: string; side?: 'left' | 'right' }) {
    return (
        <DialogPrimitive.Portal>
            <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm" />
            <DialogPrimitive.Content
                className={cn(
                    // Edge-to-edge by construction (inset-y-0), so it pads for all three insets it can
                    // meet: status bar, home indicator, and the landscape cutout on the edge it hugs.
                    'fixed inset-y-0 z-50 flex w-80 max-w-[85vw] flex-col gap-1 bg-background p-4 pt-[calc(1rem+env(safe-area-inset-top))] pb-[calc(1rem+env(safe-area-inset-bottom))] shadow-xl outline-none',
                    side === 'right'
                        ? 'right-0 w-full max-w-none pr-[calc(0.75rem+env(safe-area-inset-right))] pl-[calc(0.75rem+env(safe-area-inset-left))] motion-safe:data-[state=open]:animate-sheet-from-right motion-safe:data-[state=closed]:animate-sheet-to-right'
                        : 'left-0 pl-[calc(1rem+env(safe-area-inset-left))]',
                    className,
                )}
                {...props}
            >
                {children}
                {/* Absolutely positioned, so the sheet's top padding does not move it: inset it itself. */}
                {side === 'right' ? (
                    // The trigger's geometry, restated: a size-12 box whose right edge sits 0.5rem
                    // (0.75 gutter − 0.25 overhang) from the screen's, its row starting under the
                    // 4px line — so opening the drawer swaps the word under the glyph, nothing more.
                    <DialogPrimitive.Close className="absolute top-[calc(0.25rem+env(safe-area-inset-top))] right-[calc(0.5rem+env(safe-area-inset-right))] inline-flex size-12 flex-col items-center justify-center gap-0.5 rounded-full text-muted-foreground transition hover:bg-accent">
                        <X className="size-6" aria-hidden />
                        <span className="text-[11px] leading-none">{closeLabel}</span>
                    </DialogPrimitive.Close>
                ) : (
                    <DialogPrimitive.Close
                        className="absolute right-3 top-[calc(0.75rem+env(safe-area-inset-top))] rounded-full p-1 text-muted-foreground transition hover:bg-accent"
                        aria-label={closeLabel}
                    >
                        <X className="size-5" />
                    </DialogPrimitive.Close>
                )}
            </DialogPrimitive.Content>
        </DialogPrimitive.Portal>
    );
}
