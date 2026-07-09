import type { ComponentProps } from 'react';
import { computeInitial } from '@/lib/identity-mark';
import { cn } from '@/lib/utils';

/**
 * Neutral fallback shown when a member or community has no image: the name's initial on a muted
 * ground. It stays achromatic so the badge never reads as a color the user chose; the initial keeps
 * entities apart in dense grids. Callers own size, rounding, text size, and the a11y semantics
 * (`aria-hidden` when adjacent text already names the entity, else `role="img" aria-label`).
 */
export function InitialBadge({ name, className, ...props }: { name: string } & ComponentProps<'span'>) {
    return (
        <span
            className={cn('inline-flex items-center justify-center bg-muted font-bold leading-none text-muted-foreground', className)}
            {...props}
        >
            {computeInitial(name)}
        </span>
    );
}
