import { ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { Card, type CardVariant } from '@/components/card';
import { Heading } from '@/components/ui/heading';
import { cn } from '@/lib/utils';

/**
 * Renders an <h2>, the page's <h1> staying outside it, so a card-less page keeps one document
 * heading.
 */
export function SectionHeader({ title, right, className }: { title: ReactNode; right?: ReactNode; className?: string }) {
    return (
        <div className={cn('flex items-center gap-2 border-b border-border px-4 py-3 sm:px-5', className)}>
            <Heading as="h2" variant="minor" className="min-w-0 flex-1 truncate">
                {title}
            </Heading>
            {right}
        </div>
    );
}

type PanelProps = {
    title?: ReactNode;
    right?: ReactNode;
    children: ReactNode;
    className?: string;
    bodyClassName?: string;
    /** Drop the body padding when the body is a {@link List}: its rows carry their own. */
    flush?: boolean;
    /**
     * Forwarded to {@link Card}: `visible` lets a sticky descendant resolve against the page
     * scroll.
     */
    overflow?: 'hidden' | 'visible';
    variant?: CardVariant;
};

export function Panel({ title, right, children, className, bodyClassName, flush, overflow, variant }: PanelProps) {
    return (
        <Card className={className} overflow={overflow} variant={variant}>
            {title && <SectionHeader title={title} right={right} />}
            {/* Swapped, not appended: twMerge cannot resolve `px-1 lg:px-5` against `px-4 sm:px-5`. */}
            <div className={cn(flush ? undefined : variant === 'sheet' ? 'px-1 py-4 lg:px-5' : 'px-4 py-4 sm:px-5', bodyClassName)}>{children}</div>
        </Card>
    );
}

export function List({ children, className }: { children: ReactNode; className?: string }) {
    return <ul className={cn('divide-y divide-border', className)}>{children}</ul>;
}

/**
 * Stretches a link over its {@link ListRow}, so the row opens it while the link is still named by
 * its own text alone. Controls in the row need `relative z-10` to stay clickable, plus
 * `pointer-events-none` + `[&>*]:pointer-events-auto` on a wrapper box that would swallow the
 * clicks between them.
 */
export const stretchedLink =
    'after:absolute after:inset-0 focus-visible:outline-none focus-visible:after:outline-2 focus-visible:after:-outline-offset-2 focus-visible:after:outline-ring';

type ListRowProps = {
    /** The row opens something: it hosts a {@link stretchedLink} in its content (on the title) and
     *  takes the hover/active feedback. */
    rowLink?: boolean;
    chevron?: boolean;
    children: ReactNode;
    className?: string;
};

export function ListRow({ rowLink, chevron, children, className }: ListRowProps) {
    const base = cn('flex min-h-11 items-center gap-3 px-4 py-3 text-foreground sm:px-5', className);
    const feedback = 'transition-colors hover:bg-muted/40 active:bg-muted/60';
    const inner = (
        <>
            {children}
            {chevron && <ChevronRight className="size-4 shrink-0 text-muted-foreground" aria-hidden />}
        </>
    );
    // `relative` makes the row the containing block the stretched link resolves against; no
    // positioned element may sit between them, or the link stretches over that one instead.
    return <li className={rowLink ? cn(base, feedback, 'relative') : base}>{inner}</li>;
}

export function Separator({ className }: { className?: string }) {
    return <div role="separator" className={cn('h-px w-full bg-border', className)} />;
}
