import type { ComponentProps } from 'react';
import { Dialog as DialogPrimitive } from 'radix-ui';
import { X } from 'lucide-react';
import { Tip } from '@/components/ui/tooltip';
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
 * What each edge costs the base sheet, which is written for a full-height side drawer: the bottom one
 * gives up `inset-y-0` for a top it does not set, and takes a cap of its own so a long sheet scrolls
 * inside itself rather than growing over the conversation it was opened from.
 */
const SHEET_SIDE = {
    left: 'left-0 pl-[calc(1rem+env(safe-area-inset-left))]',
    right: 'right-0 w-full max-w-none pr-[calc(0.75rem+env(safe-area-inset-right))] pl-[calc(0.75rem+env(safe-area-inset-left))] motion-safe:data-[state=open]:animate-sheet-from-right motion-safe:data-[state=closed]:animate-sheet-to-right',
    bottom: 'inset-x-0 top-auto bottom-0 w-full max-w-none max-h-[70dvh] overflow-y-auto rounded-t-xl border-t border-border pt-4 pb-[calc(1rem+env(safe-area-inset-bottom))] motion-safe:data-[state=open]:animate-sheet-from-bottom',
};

/**
 * `side` is the screen edge the sheet hugs. A drawer opens from the side its trigger stands on — the
 * one thing every site in the 2026-08 menu survey agreed about — so a bar that moves its hamburger
 * moves the sheet with it. The close control belongs to the trigger's side too.
 *
 * The right sheet is the tabbed look's: full-bleed, sliding from its edge (Radix holds it mounted
 * while the closed animation runs), padded to the breadcrumb bar's gutters so what the drawer and
 * the bar both draw — the brand, the menu control — lands in the same place open or shut. Its close
 * control is the trigger's twin: same box, same spot, the word "close" where "menu" stood.
 *
 * The bottom sheet answers a touch, not a trigger: it rises from the edge the thumb rests on and is
 * capped short of the screen so what it was opened from stays in view above it. It restates the top
 * padding because it has no top edge to inset from — the base's status-bar inset would otherwise
 * stand as dead space under the sheet's own rounded top.
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
                    // The trigger's geometry, restated: a size-12 box whose right edge sits 0.5rem
                    // (0.75 gutter − 0.25 overhang) from the screen's, its row starting under the
                    // 4px line — so opening the drawer swaps the word under the glyph, nothing more.
                    // Wordful, so no Tip: it is named by what it shows.
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
