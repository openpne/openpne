import { cva, type VariantProps } from 'class-variance-authority';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/**
 * The one place a heading's weight, size and color are decided (docs/internals/typography.md,
 * "Heading roles"). Color sits on each variant rather than the base, because a bare
 * `headingVariants()` call has no twMerge to resolve two color utilities.
 */
export const headingVariants = cva('font-semibold', {
    variants: {
        variant: {
            display: 'text-2xl break-words text-foreground',
            page: 'text-xl break-words text-foreground',
            pageCompose: 'text-lg break-words text-foreground lg:text-xl',
            group: 'text-lg text-foreground',
            section: 'text-base text-foreground',
            minor: 'text-sm text-foreground',
            label: 'text-xs text-muted-foreground',
            bar: 'text-base text-foreground',
        },
    },
    defaultVariants: { variant: 'page' },
});

type HeadingProps = ComponentProps<'h1'> &
    VariantProps<typeof headingVariants> & {
        as?: 'h1' | 'h2' | 'h3';
    };

export function Heading({ as: Tag = 'h1', variant, className, ...props }: HeadingProps) {
    return <Tag className={cn(headingVariants({ variant }), className)} {...props} />;
}
