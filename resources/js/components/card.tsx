import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    children: ReactNode;
    className?: string;
};

/**
 * How a card meets the screen edge below sm. Bleeding is the default: a card inset from both edges
 * wastes scarce width on a phone, and the screen edge does the job the side border was doing. The
 * top/bottom border and the shadow stay either way — they mark where the surface starts and ends, and
 * dropping them buys no width, only a weaker boundary.
 *
 * `-mx-(--frame-inset)` cancels exactly the padding MemberFrame spends, so the two must move together;
 * FrameInsetContractTest holds them to it. Each entry is a complete swap rather than an appended
 * override because tailwind-merge does not know our `rounded-card` token and so would not dedupe it
 * against `rounded-none`, leaving the outcome to stylesheet order.
 */
const CHROME = {
    bleed: '-mx-(--frame-inset) rounded-none border-y border-x-0 sm:mx-0 sm:rounded-card sm:border-x',
    inset: 'rounded-card border',
};

/**
 * Rounded card wrapping a block of page content. Clips to the rounded corners by default; pass
 * `overflow="visible"` when a descendant needs to escape the card as its scroll context (e.g. a
 * `position: sticky` toolbar resolving against the page instead of the clipped card).
 */
export function Card({
    children,
    className,
    overflow = 'hidden',
    inset = false,
}: Props & {
    overflow?: 'hidden' | 'visible';
    /**
     * Keep the card inset from the screen edges below sm — for a card whose side border carries meaning
     * of its own, like the destructive outline on a danger surface.
     */
    inset?: boolean;
}) {
    return (
        <div
            className={cn(
                overflow === 'hidden' ? 'overflow-hidden' : 'overflow-visible',
                CHROME[inset ? 'inset' : 'bleed'],
                'border-border bg-card text-card-foreground shadow-card',
                className,
            )}
        >
            {children}
        </div>
    );
}

export function CardHeader({ children, className }: Props) {
    return <div className={cn('border-b border-border px-5 py-3', className)}>{children}</div>;
}

/** Padded body for a bare Card. The narrower padding below sm is part of what the bleed buys back. */
export function CardBody({ children, className }: Props) {
    return <div className={cn('px-4 py-5 sm:px-6', className)}>{children}</div>;
}
