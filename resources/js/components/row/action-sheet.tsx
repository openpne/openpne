import { useRef, type ReactNode, type RefObject } from 'react';
import { Dialog, DialogTitle, SheetContent } from '@/components/ui/dialog';
import { useT } from '@/lib/i18n';

/** The ring is inset because the frame below clips: drawn outside, a full-width item's own would be cut away. */
export const SHEET_ITEM =
    'flex min-h-12 w-full items-center gap-3 px-3 text-left text-base transition hover:bg-accent focus-visible:outline-none focus-visible:inset-ring-2 focus-visible:inset-ring-ring disabled:pointer-events-none disabled:opacity-50';

export const SHEET_GROUP = 'overflow-hidden rounded-xl border border-border bg-card divide-y divide-border';

/**
 * A finger's overlay for a row's controls: named for the reader who cannot see it, drawn as a grabber
 * for everyone else. Opened by its own button rather than a Radix trigger, so it is told where focus
 * goes back to, and `onClosed` runs once it is there.
 */
export function ActionSheet({
    open,
    onOpenChange,
    title,
    returnFocusTo,
    onClosed,
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    returnFocusTo: RefObject<HTMLElement | null>;
    onClosed?: () => void;
    children: ReactNode;
}) {
    const t = useT();
    const contentRef = useRef<HTMLDivElement>(null);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <SheetContent
                ref={contentRef}
                tabIndex={-1}
                side="bottom"
                closeLabel={t('Close')}
                aria-describedby={undefined}
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
                }}
            >
                <DialogTitle className="sr-only">{title}</DialogTitle>
                <span aria-hidden className="mx-auto mb-6 h-1 w-10 shrink-0 rounded-full bg-border" />
                {children}
            </SheetContent>
        </Dialog>
    );
}
