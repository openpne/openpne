import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/**
 * Its weight is site identity rather than text hierarchy, so it sits outside the emphasis rules
 * (docs/internals/typography.md, "What the rule does not cover"). Callers pass layout only; the
 * lockup owns weight and size.
 */
export function BrandName({ size = 'md', className }: { size?: 'md' | 'lg'; className?: string }) {
    const { name } = usePage<PageProps>().props;

    return <span className={cn('font-bold', size === 'lg' && 'text-lg', className)}>{name}</span>;
}
