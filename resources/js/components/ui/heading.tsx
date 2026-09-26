import { cva, type VariantProps } from 'class-variance-authority';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/**
 * The one place a heading's weight, size and color are decided (docs/internals/typography.md,
 * "Heading roles"). Color is a compound of rank and tone rather than part of either, because a bare
 * `headingVariants()` call has no twMerge to resolve two color utilities.
 */
export const headingVariants = cva('font-semibold', {
    variants: {
        variant: {
            display: 'text-2xl break-words',
            page: 'text-xl break-words',
            pageCompose: 'text-lg break-words lg:text-xl',
            group: 'text-lg',
            section: 'text-base',
            minor: 'text-sm',
            label: 'text-xs',
            bar: 'text-base',
        },
        tone: {
            default: '',
            destructive: '',
        },
        divided: {
            true: 'border-b border-border pb-2',
        },
    },
    compoundVariants: [
        { variant: ['display', 'page', 'pageCompose', 'group', 'section', 'minor', 'bar'], tone: 'default', class: 'text-foreground' },
        { variant: 'label', tone: 'default', class: 'text-muted-foreground' },
        { tone: 'destructive', class: 'text-destructive' },
    ],
    defaultVariants: { variant: 'page', tone: 'default' },
});

type HeadingProps = ComponentProps<'h1'> &
    VariantProps<typeof headingVariants> & {
        as?: 'h1' | 'h2' | 'h3';
    };

export function Heading({ as: Tag = 'h1', variant, tone, divided, className, ...props }: HeadingProps) {
    return <Tag className={cn(headingVariants({ variant, tone, divided }), className)} {...props} />;
}
