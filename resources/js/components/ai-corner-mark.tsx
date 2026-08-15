import { useT } from '@/lib/i18n';

/** Follows the face the tag sits on: the Avatar sizes, plus `lg` for a grid tile. */
export type AiCornerMarkSize = 'xs' | 'sm' | 'md' | 'lg';

const sizeClass: Record<AiCornerMarkSize, string> = {
    xs: 'text-[7px] px-[2px]',
    sm: 'text-[7px] px-[2px]',
    md: 'text-[8px] px-[3px]',
    lg: 'text-[9px] px-[3px]',
};

/**
 * The AI corner tag: what says so where a face is drawn, whether or not a name is beside it (avatars,
 * roster grids, the right rail's tiles). Deliberately quiet — it wears the AiChip's muted register, so
 * a row carrying both reads as one statement rather than two. Its own fill and a background-coloured
 * ring keep it legible over an uploaded photo.
 *
 * Positioned against the caller's `relative` box, which must be the face itself so the tag lands on
 * its corner. Always `aria-hidden`: the fact reaches the a11y tree through the AiChip beside the name
 * or through an accessible name the caller builds with `markedName` — once, either way.
 *
 * Renders nothing for a human, so a call site passes the fact rather than guarding on it.
 */
export function AiCornerMark({ isAi, size = 'md' }: { isAi: boolean; size?: AiCornerMarkSize }) {
    const t = useT();

    if (!isAi) {
        return null;
    }

    return (
        <span
            aria-hidden
            className={`absolute -right-0.5 -bottom-0.5 rounded-sm bg-muted leading-[1.4] text-muted-foreground ring-1 ring-background ${sizeClass[size]}`}
        >
            {t('AI')}
        </span>
    );
}
