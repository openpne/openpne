import { cva, type VariantProps } from 'class-variance-authority';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

const textareaVariants = cva(
    // text-base on mobile: a <16px control makes iOS Safari auto-zoom on focus, and the
    // zoom persists across Inertia's SPA navigations (page looks cut off on the right).
    'flex min-h-24 w-full border border-field-border bg-field px-3 py-2 text-base text-foreground shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 aria-[invalid=true]:border-destructive aria-[invalid=true]:ring-2 aria-[invalid=true]:ring-destructive/30 md:text-sm',
    {
        variants: {
            variant: {
                default: 'rounded-field',
                // The line-height and padding add up to the 44px the buttons beside a chat composer stand at.
                chat: 'rounded-2xl py-2.25 leading-6 placeholder:overflow-hidden placeholder:text-ellipsis placeholder:whitespace-nowrap',
            },
        },
        defaultVariants: { variant: 'default' },
    },
);

type Props = ComponentProps<'textarea'> & VariantProps<typeof textareaVariants>;

export function Textarea({ className, variant, ...props }: Props) {
    return <textarea className={cn(textareaVariants({ variant }), className)} {...props} />;
}

export { textareaVariants };
