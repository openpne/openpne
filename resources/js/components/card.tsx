import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    children: ReactNode;
    className?: string;
};

/**
 * What a bleeding surface does to its own edges: out through the frame's padding below lg, back
 * inside it at lg. Applied by `bleed` itself rather than passed in, and exported because the
 * composer is not a Card and has to end on the same two lines the card it sits under does — one
 * source, so the pair cannot drift apart the 16px they had drifted.
 */
export const BLEED_EDGES = '-mx-3 sm:-mx-4 lg:mx-0';

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
 * Two screens ask for something else, and they ask for different things:
 *
 * - `sheet` **drops the surface**: the compose sheet below lg is one screen with one job, and its
 *   fields keep boxes of their own, so the card underneath them was a second frame around a first.
 * - `bleed` **keeps the surface and drops the inset**: a conversation below lg is the whole screen, and
 *   the page margin plus the frame were taking a quarter of every line before the row's own padding
 *   began (the reader is reading sentences, not scanning rows). The card colour stays, because it is
 *   what tells the reader across the whole app that this is content — a diary entry, a topic and a
 *   conversation should not be read off different backgrounds. What is dropped is the rounding, the
 *   side borders and the margin; the top border keeps it apart from the page above, and below it ends
 *   where its colour ends — against the composer's own `border-t` where there is a composer, and
 *   against the page where there is none (a conversation with someone who has left has no bar).
 *
 * So the device finding is not overturned by `bleed`: it was about losing the *surface*, which `bleed`
 * does not do.
 */
export function Card({
    children,
    className,
    overflow = 'hidden',
    sheet = false,
    bleed = false,
}: Props & { overflow?: 'hidden' | 'visible'; sheet?: boolean; bleed?: boolean }) {
    return (
        <div
            className={cn(
                overflow === 'hidden' ? 'overflow-hidden' : 'overflow-visible',
                // Swapped rather than overridden: `rounded-card` is a custom token, which twMerge does
                // not treat as the same utility as the class that would undo it.
                // Three strings rather than classes layered over each other: `rounded-card` is a custom
                // token and the borders differ per side, neither of which twMerge can resolve against
                // a later override.
                sheet
                    ? 'text-card-foreground lg:rounded-card lg:border lg:border-border lg:bg-card lg:shadow-card'
                    : bleed
                      ? `${BLEED_EDGES} border-t border-border bg-card text-card-foreground lg:rounded-card lg:border lg:shadow-card`
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
