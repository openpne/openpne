import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    children: ReactNode;
    className?: string;
};

/**
 * Rounded card wrapping a block of page content. Clips to the rounded corners by default; pass
 * `overflow="visible"` when a descendant needs to escape the card as its scroll context (e.g. a
 * `position: sticky` toolbar resolving against the page instead of the clipped card), or
 * `overflow="clip"` to have both — `clip` establishes no scroll container, so a sticky descendant
 * still resolves against the page while the corners keep clipping. A browser without `overflow: clip`
 * drops the declaration and lands on `visible`, which is the same trade the other option makes.
 *
 * The card stays inset from the screen edges at every width. Running it edge to edge does buy width,
 * but it also takes away the strip of page background either side — which is what makes a card read as
 * a surface lying on the page rather than as the page itself. Tested on device, that flattened every
 * screen into one field of card color divided by lines. The width comes from tighter padding instead.
 * That judgment is about screens the reader browses, which is why `sheet` is a scoped exception: the
 * compose sheet below lg is a single screen with a single job, and there the frame is the divider.
 */
export function Card({
    children,
    className,
    overflow = 'hidden',
    sheet = false,
}: Props & { overflow?: 'hidden' | 'clip' | 'visible'; sheet?: boolean }) {
    return (
        <div
            className={cn(
                // A record over the union rather than a chain of ternaries: the chain would end in a
                // catch-all, and a mode added without a branch would silently take it.
                { hidden: 'overflow-hidden', clip: 'overflow-clip', visible: 'overflow-visible' }[overflow],
                // Swapped rather than overridden: `rounded-card` is a custom token, which twMerge does
                // not treat as the same utility as the class that would undo it.
                sheet
                    ? 'text-card-foreground lg:rounded-card lg:border lg:border-border lg:bg-card lg:shadow-card'
                    : 'rounded-card border border-border bg-card text-card-foreground shadow-card',
                className,
            )}
        >
            {children}
        </div>
    );
}

export function CardHeader({ children, className }: Props) {
    return <div className={cn('border-b border-border px-4 py-3 sm:px-5', className)}>{children}</div>;
}

export function CardBody({ children, className }: Props) {
    return <div className={cn('px-4 py-5 sm:px-6', className)}>{children}</div>;
}
