import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    children: ReactNode;
    className?: string;
};

/**
 * Applied by `bleed` itself and exported because the composer is not a Card, yet has to end on the
 * same two lines, so the pair cannot drift.
 */
export const BLEED_EDGES = '-mx-3 sm:-mx-4 lg:mx-0';

/**
 * `overflow="visible"` where a descendant has to escape the card as its scroll context — a sticky
 * toolbar resolving against the page. `variant` is one prop rather than two flags so a card cannot
 * ask for both: `sheet` drops the surface, `bleed` keeps the surface and drops the inset.
 */
export type CardVariant = 'sheet' | 'bleed';

export function Card({
    children,
    className,
    overflow = 'hidden',
    variant,
}: Props & { overflow?: 'hidden' | 'visible'; variant?: CardVariant }) {
    return (
        <div
            className={cn(
                overflow === 'hidden' ? 'overflow-hidden' : 'overflow-visible',
                // Three strings rather than classes layered over each other: `rounded-card` is a custom
                // token and the borders differ per side, neither of which twMerge can resolve against
                // a later override.
                variant === 'sheet'
                    ? 'text-card-foreground lg:rounded-card lg:border lg:border-border lg:bg-card lg:shadow-card'
                    : variant === 'bleed'
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
