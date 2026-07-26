import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { Card, type CardBleed } from '@/components/card';
import { cn } from '@/lib/utils';

/** Body padding per bleed tier; `full` keeps none below sm so its children own `--frame-inset`. */
const BODY_PADDING: Record<'inset' | 'bleed' | 'full', string> = {
    inset: 'px-5 py-4',
    bleed: 'px-(--frame-inset) py-4 sm:px-5',
    full: 'py-4 sm:px-5',
};

/**
 * Underlined title band for the top of a Panel/Card (or a titled subsection). The page <h1> stays
 * outside; this renders an <h2> so a card-less page keeps one document heading.
 */
export function SectionHeader({ title, right, className }: { title: ReactNode; right?: ReactNode; className?: string }) {
    return (
        <div className={cn('flex items-center gap-2 border-b border-border px-5 py-3', className)}>
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
    /**
     * Forwarded to {@link Card}: run edge to edge below `sm`, and tighten the body padding to match.
     * For title-less panels — a {@link SectionHeader} keeps its own `px-5` and would not line up.
     *
     * `'full'` additionally hands the horizontal inset to the body's own children: the panel keeps no
     * side padding below sm, so each child (a {@link FormRow}, the compose editor) spends
     * `--frame-inset` itself and can align its text with the page heading. A child that forgets to
     * would sit at x=0, so `'full'` is only for bodies built out of inset-owning parts.
     */
    bleed?: CardBleed;
};

/**
 * The one page-level container a card-less form/section reaches for, so content stops floating on the
 * page background: a Card + optional {@link SectionHeader} band + padded body. Use `flush` when the
 * body is a {@link List} (rows carry their own padding).
 */
export function Panel({ title, right, children, className, bodyClassName, flush, overflow, bleed }: PanelProps) {
    return (
        <Card className={className} overflow={overflow} bleed={bleed}>
            {title && <SectionHeader title={title} right={right} />}
            <div className={cn(flush ? undefined : BODY_PADDING[bleed === 'full' ? 'full' : bleed ? 'bleed' : 'inset'], bodyClassName)}>
                {children}
            </div>
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
export function ListRow({ href, chevron, children, className }: ListRowProps) {
    const base = cn('flex min-h-11 items-center gap-3 px-5 py-3 text-foreground', className);
    const inner = (
        <>
            {children}
            {chevron && <ChevronRight className="size-4 shrink-0 text-muted-foreground" aria-hidden />}
        </>
    );
    return href ? (
        <li>
            <Link href={href} className={cn(base, 'transition-colors hover:bg-muted/40 active:bg-muted/60')}>
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
