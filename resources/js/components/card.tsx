import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    children: ReactNode;
    className?: string;
};

/**
 * Rounded card wrapping a block of page content. Clips to the rounded corners by default; pass
 * `overflow="visible"` when a descendant needs to escape the card as its scroll context (e.g. a
 * `position: sticky` toolbar resolving against the page instead of the clipped card).
 *
 * The card stays inset from the screen edges. Running it edge to edge does buy width, but it also
 * takes away the strip of page background either side — which is what makes a card read as a surface
 * lying on the page rather than as the page itself. Tested on device, that flattened every screen into
 * one field of card color divided by lines. The width comes from tighter padding instead.
 *
 * That judgment is about screens the reader browses, and `sheet` drops the chrome below lg for the two
 * screens it does not describe. Each is one surface with one job, and each has something other than
 * the frame to divide by — which is the half of this rule that is easy to drop:
 *
 * - the compose sheet, where the fields keep their own boxes;
 * - a conversation (group talk, a direct message), where the composer's own `border-t` draws the line
 *   the frame used to. That border is what keeps the list and the composer from reading as the one
 *   field this rule was written against — taking it away is what would break the exception, not
 *   anything about the card.
 *
 * A third would need both halves, not just the first.
 */
export function Card({
    children,
    className,
    overflow = 'hidden',
    sheet = false,
}: Props & { overflow?: 'hidden' | 'visible'; sheet?: boolean }) {
    return (
        <div
            className={cn(
                overflow === 'hidden' ? 'overflow-hidden' : 'overflow-visible',
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
