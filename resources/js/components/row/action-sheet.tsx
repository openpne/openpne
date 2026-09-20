import { useLayoutEffect, useRef, type ReactNode, type RefObject } from 'react';
import { Dialog, DialogTitle, SheetContent } from '@/components/ui/dialog';
import { headingVariants } from '@/components/ui/heading';
import { useT } from '@/lib/i18n';

/** The ring is inset because the frame below clips: drawn outside, a full-width item's own would be cut away. */
export const SHEET_ITEM =
    'flex min-h-12 w-full items-center gap-3 px-3 text-left text-base transition hover:bg-accent focus-visible:outline-none focus-visible:inset-ring-2 focus-visible:inset-ring-ring disabled:pointer-events-none disabled:opacity-50';

export const SHEET_GROUP = 'overflow-hidden rounded-xl border border-border bg-card divide-y divide-border';

/** How long after the finger lifts the click it synthesises can still arrive. */
const RELEASE_MS = 400;

/** Opened by a press rather than a Radix trigger, so it is told where focus goes back to; `returnFocusTo` must still be in the document when the sheet closes. */
export function ActionSheet({
    open,
    onOpenChange,
    title,
    returnFocusTo,
    titleVisible = false,
    onOpened,
    onClosed,
    openedByPress = false,
    animated = true,
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    returnFocusTo: RefObject<HTMLElement | null>;
    /** Shown as a heading rather than read only to a screen reader. */
    titleVisible?: boolean;
    /** Run once the sheet holds focus, for what needs its content in the document. */
    onOpened?: () => void;
    /** Run once the sheet has closed and focus is back where `returnFocusTo` points. */
    onClosed?: () => void;
    /** True when a finger still on the screen opened it: its lifting lands a click on whatever the sheet now covers. */
    openedByPress?: boolean;
    /** False for a sheet drawn in place, with no slide in or out. */
    animated?: boolean;
    children: ReactNode;
}) {
    const t = useT();
    const contentRef = useRef<HTMLDivElement>(null);
    // The press that opened the sheet is still down until its first pointerup; the click that release synthesises is not a choice, a later finger's is.
    const press = useRef<{ down: boolean; releasedAt: number }>({ down: false, releasedAt: 0 });
    // A layout effect: a passive effect ran 15ms after the sheet's node on a phone, and a finger lifts in that frame (not reproducible under act(), which flushes both).
    useLayoutEffect(() => {
        if (!open || !openedByPress) {
            return;
        }
        press.current = { down: true, releasedAt: 0 };
        const release = () => {
            if (press.current.down) {
                press.current = { down: false, releasedAt: performance.now() };
            }
        };
        const another = () => {
            press.current = { down: false, releasedAt: 0 };
        };
        document.addEventListener('pointerup', release, true);
        document.addEventListener('pointercancel', release, true);
        document.addEventListener('pointerdown', another, true);

        return () => {
            document.removeEventListener('pointerup', release, true);
            document.removeEventListener('pointercancel', release, true);
            document.removeEventListener('pointerdown', another, true);
        };
    }, [open, openedByPress]);
    const stillReleasing = () => press.current.releasedAt > 0 && performance.now() - press.current.releasedAt < RELEASE_MS;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <SheetContent
                ref={contentRef}
                tabIndex={-1}
                side="bottom"
                animated={animated}
                closeLabel={t('Close')}
                aria-describedby={undefined}
                onInteractOutside={(event) => {
                    if (stillReleasing()) {
                        event.preventDefault();
                        contentRef.current?.focus({ preventScroll: true });
                    }
                }}
                onClickCapture={(event) => {
                    if (stillReleasing()) {
                        event.preventDefault();
                        event.stopPropagation();
                        contentRef.current?.focus({ preventScroll: true });
                    }
                }}
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    returnFocusTo.current?.focus({ preventScroll: true });
                    onClosed?.();
                }}
                // The trap's default first stop is the first control, and a focus ring drawn there
                // reads as "you hold this one" on a tile nobody pressed.
                onOpenAutoFocus={(event) => {
                    event.preventDefault();
                    contentRef.current?.focus({ preventScroll: true });
                    onOpened?.();
                }}
            >
                <span aria-hidden className="mx-auto mb-6 h-1 w-10 shrink-0 rounded-full bg-border" />
                <DialogTitle className={titleVisible ? `${headingVariants({ variant: 'section' })} mb-4 text-center` : 'sr-only'}>{title}</DialogTitle>
                {children}
            </SheetContent>
        </Dialog>
    );
}
