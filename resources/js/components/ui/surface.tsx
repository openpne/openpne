import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { Card } from '@/components/card';
import { cn } from '@/lib/utils';

/**
 * Underlined title band for the top of a Panel/Card (or a titled subsection). The page <h1> stays
 * outside; this renders an <h2> so a card-less page keeps one document heading.
 */
export function SectionHeader({ title, right, className }: { title: ReactNode; right?: ReactNode; className?: string }) {
    return (
        <div className={cn('flex items-center gap-2 border-b border-border px-4 py-3 sm:px-5', className)}>
            <h2 className="min-w-0 flex-1 truncate text-sm font-semibold text-foreground">{title}</h2>
            {right}
        </div>
    );
}

type PanelProps = {
    /** Optional header-band title (ReactNode, not string-only). Omit for a plain padded card. */
    title?: ReactNode;
    right?: ReactNode;
    children: ReactNode;
    className?: string;
    bodyClassName?: string;
    /** Drop the body padding so an edge-to-edge <List> sits flush under the header. */
    flush?: boolean;
    /** Forwarded to {@link Card}: `visible` lets a sticky descendant resolve against the page scroll. */
    overflow?: 'hidden' | 'visible';
};

/**
 * The one page-level container a card-less form/section reaches for, so content stops floating on the
 * page background: a Card + optional {@link SectionHeader} band + padded body. Use `flush` when the
 * body is a {@link List} (rows carry their own padding).
 */
export function Panel({ title, right, children, className, bodyClassName, flush, overflow }: PanelProps) {
    return (
        <Card className={className} overflow={overflow}>
            {title && <SectionHeader title={title} right={right} />}
            {/* Tighter below sm, where the width is scarce: the frame, this padding and a field box each
                take a bite out of the same line, and 20px here was the widest of the three. */}
            <div className={cn(flush ? undefined : 'px-4 py-4 sm:px-5', bodyClassName)}>{children}</div>
        </Card>
    );
}

/** A vertical list with hairline dividers between rows. Pair with {@link ListRow}. */
export function List({ children, className }: { children: ReactNode; className?: string }) {
    return <ul className={cn('divide-y divide-border', className)}>{children}</ul>;
}

type ListRowProps = {
    /** When set, the whole row becomes an Inertia Link with hover/active feedback. */
    href?: string;
    /** Accessible name for the row link on rows that carry their own controls (a checkbox, a button):
     *  the link is laid over the row instead of wrapping it, since an anchor may not nest interactive
     *  elements. Controls take `relative z-10` to stay above it. */
    linkLabel?: string;
    /** Show a trailing chevron (decorative — only meaningful on linked rows). */
    chevron?: boolean;
    children: ReactNode;
    className?: string;
};

/**
 * One list row. Always renders an <li>; with `href` the content is wrapped in an Inertia Link. The
 * caller lays out the row content (e.g. avatar + a `min-w-0 flex-1` text block); this appends the
 * chevron. Not for grid/tile lists — keep those as their own layout.
 */
export function ListRow({ href, linkLabel, chevron, children, className }: ListRowProps) {
    const base = cn('flex min-h-11 items-center gap-3 px-4 py-3 text-foreground sm:px-5', className);
    const feedback = 'transition-colors hover:bg-muted/40 active:bg-muted/60';
    const inner = (
        <>
            {children}
            {chevron && <ChevronRight className="size-4 shrink-0 text-muted-foreground" aria-hidden />}
        </>
    );
    if (href && linkLabel) {
        return (
            <li className={cn(base, feedback, 'relative')}>
                {inner}
                {/* Last in the row so it stays after the controls in tab order; the focus outline is
                    drawn inset, since the overlay sits flush against the row edges. */}
                <Link
                    href={href}
                    aria-label={linkLabel}
                    className="absolute inset-0 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                />
            </li>
        );
    }
    return href ? (
        <li>
            <Link href={href} className={cn(base, feedback)}>
                {inner}
            </Link>
        </li>
    ) : (
        <li className={base}>{inner}</li>
    );
}

/** Token hairline between stacked form sections or content blocks. */
export function Separator({ className }: { className?: string }) {
    return <div role="separator" className={cn('h-px w-full bg-border', className)} />;
}
