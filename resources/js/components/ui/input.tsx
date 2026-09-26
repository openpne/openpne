import { cva, type VariantProps } from 'class-variance-authority';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

const inputVariants = cva(
    // text-base on mobile: a <16px control makes iOS Safari auto-zoom on focus, and the
    // zoom persists across Inertia's SPA navigations (page looks cut off on the right).

    // min-w-0 so `w-full` is the last word on the width: the date/time widgets carry an
    // intrinsic minimum that would otherwise push the field past its column.
    'flex min-h-11 w-full min-w-0 border border-field-border bg-field px-3 py-2 text-base text-foreground shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 aria-[invalid=true]:border-destructive aria-[invalid=true]:ring-2 aria-[invalid=true]:ring-destructive/30 md:text-sm',
    {
        variants: {
            variant: {
                default: 'rounded-field',
                // The right padding keeps the text clear of a SearchSubmitButton laid over the field.
                search: 'rounded-full pl-5 pr-11',
                pill: 'rounded-full px-5',
                // Not a `read-only:` variant on the base: `:read-only` also matches a disabled input.
                readonly: 'rounded-field bg-muted text-muted-foreground',
            },
        },
        defaultVariants: { variant: 'default' },
    },
);

type Props = ComponentProps<'input'> & VariantProps<typeof inputVariants>;

export function Input({ className, variant, ...props }: Props) {
    return (
        <input
            className={cn(
                inputVariants({ variant }),
                // iOS Safari sizes a date field by its own UA rules and ignores the author width, so
                // appearance-none puts the box back under this stylesheet and the value part is
                // left-aligned to sit like every other input's text.
                props.type === 'date' && 'appearance-none [&::-webkit-date-and-time-value]:text-left',
                className,
            )}
            {...props}
        />
    );
}

export { inputVariants };
