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
                // Checked = filled with primary (the segmented-control selected look, same language as
                // the primary button). With a neutral primary, a light tint reads as gray = disabled,
                // and border+ring alone is too subtle in dark — the inversion is unambiguous in both.
                'flex cursor-pointer items-center gap-2 rounded-full border border-input px-3.5 py-1.5 text-sm text-foreground transition-colors hover:border-primary/40 has-[:checked]:border-primary has-[:checked]:bg-primary has-[:checked]:text-primary-foreground has-[:disabled]:opacity-60 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring',
                className,
            )}
        >
            {/* The checked dot flips to the foreground accent so it stays visible on the filled pill. */}
            <input type="radio" className="size-3.5 shrink-0 accent-primary outline-none checked:accent-primary-foreground" {...props} />
            <span>{label}</span>
        </label>
    );
}
