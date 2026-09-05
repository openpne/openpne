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
 * Positioned against the caller's `relative` box, which must be the face itself so the tag lands on
 * its corner. Always `aria-hidden`: the fact reaches the a11y tree through the AiChip beside the
 * name, or through an accessible name the caller builds with `markedName`.
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
