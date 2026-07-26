import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    children: ReactNode;
    className?: string;
};

/** How the card meets the screen edge below sm. `true` keeps the hairline outline; 'full' drops it. */
export type CardBleed = boolean | 'full';

/**
 * The three ways a card can meet the viewport below sm. Each is a complete swap rather than an
 * appended override because tailwind-merge does not know our custom `rounded-card` / `shadow-card`
 * tokens and so would not dedupe them against `rounded-none` / `shadow-none`, leaving the outcome to
 * stylesheet order.
 *
 * `bleed` and `full` both cancel MemberFrame's inset with `-mx-(--frame-inset)`, because a rounded
 * card inset from both edges wastes scarce width on a screen this narrow. They differ in what marks
 * the surface: `bleed` keeps top/bottom hairlines, `full` drops every border and the shadow so the
 * background/card colour difference alone marks it — the note/x.com treatment, and the only one that
 * lets the content inside align its text with the page heading.
 */
const CHROME: Record<'inset' | 'bleed' | 'full', string> = {
    inset: 'rounded-card border shadow-card',
    bleed: '-mx-(--frame-inset) rounded-none border-y border-x-0 shadow-card sm:mx-0 sm:rounded-card sm:border-x',
    full: '-mx-(--frame-inset) rounded-none border-0 sm:mx-0 sm:rounded-card sm:border sm:shadow-card',
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
    bleed = false,
}: Props & { overflow?: 'hidden' | 'visible'; bleed?: CardBleed }) {
    return (
        <div
            className={cn(
                overflow === 'hidden' ? 'overflow-hidden' : 'overflow-visible',
                CHROME[bleed === 'full' ? 'full' : bleed ? 'bleed' : 'inset'],
                'border-border bg-card text-card-foreground',
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

export function CardBody({ children, className }: Props) {
    return <div className={cn('px-6 py-5', className)}>{children}</div>;
}
