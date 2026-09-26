import { cva, type VariantProps } from 'class-variance-authority';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

const selectVariants = cva(
    // text-base on mobile: a <16px control makes iOS Safari auto-zoom on focus, and the
    // zoom persists across Inertia's SPA navigations (page looks cut off on the right).
    'flex min-h-11 w-full border border-field-border bg-field px-3 py-2 text-base text-foreground shadow-sm transition-colors focus-visible:border-ring focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 aria-[invalid=true]:border-destructive aria-[invalid=true]:ring-2 aria-[invalid=true]:ring-destructive/30 md:text-sm',
    {
        variants: {
            variant: {
                default: 'rounded-field',
                pill: 'rounded-full pl-5',
            },
            size: {
                default: '',
                compact: 'min-h-7 px-2 py-0.5 text-sm shadow-none',
            },
        },
        defaultVariants: { variant: 'default', size: 'default' },
    },
);

type Props = Omit<ComponentProps<'select'>, 'size'> & VariantProps<typeof selectVariants>;

/**
 * Native rather than a combobox primitive: it keeps the platform picker and binds straight to
 * Inertia's useForm.
 */
export function Select({ className, variant, size, ...props }: Props) {
    return <select className={cn(selectVariants({ variant, size }), className)} {...props} />;
}

export { selectVariants };
