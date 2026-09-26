import { cva, type VariantProps } from 'class-variance-authority';
import type { ComponentProps } from 'react';
import { Spinner } from '@/components/spinner';
import { cn } from '@/lib/utils';

const buttonVariants = cva(
    'inline-flex items-center justify-center gap-2 whitespace-nowrap text-sm transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:pointer-events-none disabled:opacity-50 active:scale-[0.98]',
    {
        variants: {
            variant: {
                default: 'bg-primary text-primary-foreground hover:bg-primary/90',
                destructive: 'bg-destructive text-destructive-foreground hover:bg-destructive/90',
                secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
                outline: 'border border-input bg-background hover:bg-accent hover:text-accent-foreground',
                ghost: 'hover:bg-accent hover:text-accent-foreground',
                link: 'text-link underline-offset-4 hover:underline',
                row: 'text-link hover:bg-muted hover:text-link',
            },
            size: {
                // The default keeps a 44px touch target (min-h-11).
                default: 'min-h-11 px-5 py-2',
                sm: 'min-h-9 px-3',
                lg: 'min-h-12 px-6 text-base',
                icon: 'size-11',
                row: 'min-h-9 w-full px-3 py-3 sm:px-5',
            },
            // The radius lives here rather than in the base so that a variant swaps it instead of
            // stacking a second radius class on top.
            shape: {
                field: 'rounded-field',
                pill: 'rounded-full',
            },
            tone: {
                default: '',
                muted: 'text-muted-foreground',
            },
            elevated: {
                true: 'shadow-md',
            },
            edge: {
                top: 'border-t border-border',
                bottom: 'border-b border-border',
            },
        },
        compoundVariants: [{ variant: 'row', class: 'rounded-none' }],
        defaultVariants: { variant: 'default', size: 'default', shape: 'field', tone: 'default' },
    },
);

type Props = ComponentProps<'button'> & VariantProps<typeof buttonVariants> & { loading?: boolean };

/**
 * Defaults to `type="button"`, so a button inside a form submits only when the caller asks for
 * `type="submit"`.
 */
export function Button({ className, variant, size, shape, tone, elevated, edge, loading = false, disabled, type = 'button', children, ...props }: Props) {
    return (
        <button
            type={type}
            className={cn(buttonVariants({ variant, size, shape, tone, elevated, edge }), className)}
            disabled={disabled || loading}
            {...props}
        >
            {loading && <Spinner size={4} />}
            {children}
        </button>
    );
}

export { buttonVariants };
