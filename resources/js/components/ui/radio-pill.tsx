import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = ComponentProps<'input'> & {
    label: ReactNode;
};

/**
 * A radio rendered as a compact pill, for short single-choice settings laid out as one wrapping
 * row (2-5 options) — the scannable counterpart to RadioCard for options that need no description.
 * Same selection language as RadioCard (primary border + ring via `has-[:checked]`), and the radio
 * input stays visible so the group still reads as a choice at a glance.
 */
export function RadioPill({ label, className, ...props }: Props) {
    return (
        <label
            className={cn(
                // Unlike RadioCard, the checked state also tints the fill: a pill has no description to
                // keep readable, and in dark mode the border+ring alone is too subtle to spot.
                'flex cursor-pointer items-center gap-2 rounded-full border border-input px-3.5 py-1.5 text-sm transition-colors hover:border-primary/40 has-[:checked]:border-primary has-[:checked]:bg-primary/10 has-[:checked]:ring-1 has-[:checked]:ring-primary has-[:disabled]:opacity-60 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring',
                className,
            )}
        >
            <input type="radio" className="size-3.5 shrink-0 accent-primary outline-none" {...props} />
            <span className="text-foreground">{label}</span>
        </label>
    );
}
