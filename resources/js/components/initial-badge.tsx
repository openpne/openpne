import type { ComponentProps } from 'react';
import { computeInitial, pickReadableTextColor } from '@/lib/identity-mark';
import { cn } from '@/lib/utils';

/**
 * The default ground stays achromatic, so the badge never reads as a colour the member did not pick.
 * The 20% tint is what keeps the neutral fill legible on both bg-background and bg-card, and the
 * text needs `foreground/75` rather than `text-muted-foreground` to hold 4.5:1 on it.
 */
/** Callers pass the a11y semantics: `aria-hidden` when adjacent text names the entity, else `role="img"` with `aria-label`. */
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
