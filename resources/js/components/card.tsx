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
 * The card stays inset from the screen edges at every width. Running it edge to edge does buy width,
 * but it also takes away the strip of page background either side — which is what makes a card read as
 * a surface lying on the page rather than as the page itself. Tested on device, that flattened every
 * screen into one field of card color divided by lines. The width comes from tighter padding instead.
 */
export function Card({ children, className, overflow = 'hidden' }: Props & { overflow?: 'hidden' | 'visible' }) {
    return (
        <div
            className={cn(
                overflow === 'hidden' ? 'overflow-hidden' : 'overflow-visible',
                'rounded-card border border-border bg-card text-card-foreground shadow-card',
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
