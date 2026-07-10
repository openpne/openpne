import type { ComponentProps } from 'react';
import { computeInitial, pickReadableTextColor } from '@/lib/identity-mark';
import { cn } from '@/lib/utils';

/**
 * Fallback shown when a member or community has no image: the name's initial on a muted ground, or
 * on the member's chosen `color` (a hex from the server). The default stays achromatic so the badge
 * never reads as a color the user didn't pick; a chosen color is deliberate self-expression and
 * renders as a solid fill. The initial keeps entities apart in dense grids. Callers own size,
 * rounding, text size, and the a11y semantics (`aria-hidden` when adjacent text already names the
 * entity, else `role="img" aria-label`).
 *
 * The neutral fill must read against both bg-background (right rail) and bg-card (list rows) — flat
 * bg-muted sat within 1.05:1 of the page and disappeared. The 20% tint keeps ~1.3:1 of edge in
 * both color modes; text needs foreground/75 (not text-muted-foreground) to hold 4.5:1 on it.
 */
type Props = { name: string; color?: string | null } & Omit<ComponentProps<'span'>, 'children' | 'color'>;

export function InitialBadge({ name, color = null, className, style, ...props }: Props) {
    return (
        <span
            className={cn(
                'inline-flex items-center justify-center font-bold leading-none',
                color ? pickReadableTextColor(color) : 'bg-muted-foreground/20 text-foreground/75',
                className,
            )}
            style={color ? { ...style, backgroundColor: color } : style}
            {...props}
        >
            {computeInitial(name)}
        </span>
    );
}
