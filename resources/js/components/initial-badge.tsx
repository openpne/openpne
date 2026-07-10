import type { ComponentProps } from 'react';
import { computeInitial } from '@/lib/identity-mark';
import { cn } from '@/lib/utils';

/**
 * Neutral fallback shown when a member or community has no image: the name's initial on a muted
 * ground. It stays achromatic so the badge never reads as a color the user chose; the initial keeps
 * entities apart in dense grids. Callers own size, rounding, text size, and the a11y semantics
 * (`aria-hidden` when adjacent text already names the entity, else `role="img" aria-label`).
 *
 * The fill must read against both bg-background (right rail) and bg-card (list rows) — flat
 * bg-muted sat within 1.05:1 of the page and disappeared. The 20% tint keeps ~1.3:1 of edge in
 * both color modes; text needs foreground/75 (not text-muted-foreground) to hold 4.5:1 on it.
 */
export function InitialBadge({ name, className, ...props }: { name: string } & Omit<ComponentProps<'span'>, 'children'>) {
    return (
        <span
            className={cn('inline-flex items-center justify-center bg-muted-foreground/20 font-bold leading-none text-foreground/75', className)}
            {...props}
        >
            {computeInitial(name)}
        </span>
    );
}
