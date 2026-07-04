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
                // Checked = the chromatic selected token (blue border + tint): a hue tint cannot be
                // mistaken for disabled-gray, and unlike a primary fill it does not shout like an
                // action button when several checked pills share one screen.
                'flex cursor-pointer items-center gap-2 rounded-full border border-input px-3.5 py-1.5 text-sm text-foreground transition-colors hover:border-selected/50 has-[:checked]:border-selected has-[:checked]:bg-selected/10 has-[:disabled]:opacity-60 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring',
                className,
            )}
        >
            <input type="radio" className="size-3.5 shrink-0 accent-selected outline-none" {...props} />
            <span>{label}</span>
        </label>
    );
}
