import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/**
 * The SNS name set beside {@link BrandMark}. Its weight is site identity, not text hierarchy, which
 * is why it sits outside the emphasis rules rather than inside them — and why it lives here instead
 * of at each of the five places that used to spell the same classes out. Callers pass layout only
 * (truncate, alignment); the lockup owns weight and size.
 */
export function BrandName({ size = 'md', className }: { size?: 'md' | 'lg'; className?: string }) {
    const { name } = usePage<PageProps>().props;

    return <span className={cn('font-bold', size === 'lg' && 'text-lg', className)}>{name}</span>;
}
