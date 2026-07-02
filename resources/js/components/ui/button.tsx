import { cva, type VariantProps } from 'class-variance-authority';
import type { ComponentProps } from 'react';
import { Spinner } from '@/components/spinner';
import { cn } from '@/lib/utils';

const buttonVariants = cva(
    'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:pointer-events-none disabled:opacity-50 active:scale-[0.98]',
    {
        variants: {
            variant: {
                default: 'bg-primary text-primary-foreground hover:bg-primary/90',
                destructive: 'bg-destructive text-destructive-foreground hover:bg-destructive/90',
                secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
                outline: 'border border-input bg-background hover:bg-accent hover:text-accent-foreground',
                ghost: 'hover:bg-accent hover:text-accent-foreground',
                link: 'text-link underline-offset-4 hover:underline',
            },
            size: {
                // The default keeps a 44px touch target (min-h-11).
                default: 'min-h-11 px-5 py-2',
                sm: 'min-h-9 px-3',
                lg: 'min-h-12 px-6 text-base',
                icon: 'size-11',
            },
        },
        defaultVariants: { variant: 'default', size: 'default' },
    },
);

type Props = ComponentProps<'button'> & VariantProps<typeof buttonVariants> & { loading?: boolean };

/**
 * Token-based button. `loading` shows a spinner and disables the control. Defaults to
 * `type="button"` so a design-system button inside a form never submits by accident — callers opt
 * into submission with `type="submit"`.
 */
export function Button({ className, variant, size, loading = false, disabled, type = 'button', children, ...props }: Props) {
    return (
        <button type={type} className={cn(buttonVariants({ variant, size }), className)} disabled={disabled || loading} {...props}>
            {loading && <Spinner size={4} />}
            {children}
        </button>
    );
}

export { buttonVariants };
