import { ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { Card, type CardVariant } from '@/components/card';
import { Heading } from '@/components/ui/heading';
import { cn } from '@/lib/utils';

/**
 * Underlined title band for the top of a Panel/Card (or a titled subsection). The page <h1> stays
 * outside; this renders an <h2> so a card-less page keeps one document heading.
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
     * Forwarded to {@link Card}. `sheet` drops the card chrome below lg, for the compose sheet's one
     * surface; `bleed` keeps the surface and drops the inset, for a conversation.
     */
    variant?: CardVariant;
};

/**
 * The one page-level container a card-less form/section reaches for, so content stops floating on the
 * page background: a Card + optional {@link SectionHeader} band + padded body. Use `flush` when the
 * body is a {@link List} (rows carry their own padding).
 */
export function Panel({ title, right, children, className, bodyClassName, flush, overflow, variant }: PanelProps) {
    return (
        <Card className={className} overflow={overflow} variant={variant}>
            {title && <SectionHeader title={title} right={right} />}
            {/* Tighter below sm, where the width is scarce: the frame, this padding and a field box each
                take a bite out of the same line, and 20px here was the widest of the three.
                A sheet trims it to 4 below lg: its fields keep their boxes, so what this buys is a
                tighter inset for them — the frame (12, 16 from sm) plus this 4 puts the field-box edge
                16px from the screen edge below sm, and 4 inside the frame at every width up to lg.
                Swapped, not appended — twMerge
                cannot resolve `px-1 lg:px-5` against `px-4 sm:px-5`. The formatting toolbar's band is
                keyed to this value (rich-text-editor.tsx). */}
            <div className={cn(flush ? undefined : variant === 'sheet' ? 'px-1 py-4 lg:px-5' : 'px-4 py-4 sm:px-5', bodyClassName)}>{children}</div>
        </Card>
    );
}

/** A vertical list with hairline dividers between rows. Pair with {@link ListRow}. */
export function List({ children, className }: { children: ReactNode; className?: string }) {
    return <ul className={cn('divide-y divide-border', className)}>{children}</ul>;
}

/**
 * Stretches a link's hit area over its {@link ListRow} — the row is the link's containing block, so
 * the whole row opens it while the link is named by its own text alone, not by everything else the
 * row shows. This is how a row becomes clickable; a row-wrapping Link would read the whole row as
 * one link and could not hold the row's own controls. Controls stay clickable with `relative z-10`;
 * give them `pointer-events-none` + `[&>*]:pointer-events-auto` when a wrapper box would otherwise
 * swallow the clicks between them.
 */
export const stretchedLink =
    'after:absolute after:inset-0 focus-visible:outline-none focus-visible:after:outline-2 focus-visible:after:-outline-offset-2 focus-visible:after:outline-ring';

type ListRowProps = {
    /** The row opens something: it hosts a {@link stretchedLink} in its content (on the title) and
     *  takes the hover/active feedback. */
    rowLink?: boolean;
    /** Show a trailing chevron (decorative — only meaningful on linked rows). */
    chevron?: boolean;
    children: ReactNode;
    className?: string;
};

/**
 * One list row. The caller lays out the row content (e.g. avatar + a `min-w-0 flex-1` text block);
 * this appends the chevron. Not for grid/tile lists — keep those as their own layout.
 */
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

/** Token hairline between stacked form sections or content blocks. */
export function Separator({ className }: { className?: string }) {
    return <div role="separator" className={cn('h-px w-full bg-border', className)} />;
}
