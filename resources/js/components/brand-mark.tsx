import { usePage } from '@inertiajs/react';
import type { CSSProperties } from 'react';
import { computeInitial, pickReadableTextColor } from '@/lib/identity-mark';
import type { PageProps } from '@/types';

/**
 * Unlike the member and group fallbacks, the colour here is one the admin chose, so the mark stays
 * chromatic.
 */
export function BrandMark({ size = 'md', className = '' }: { size?: 'sm' | 'md' | 'lg'; className?: string }) {
    const { name, snsLogo } = usePage<PageProps>().props;
    const sizeClass =
        size === 'sm'
            ? 'size-8 rounded-md text-sm'
            : size === 'lg'
              ? 'size-16 rounded-2xl text-2xl shadow-sm'
              : 'size-9 rounded-md text-base';

    if (snsLogo.url) {
        return <img src={snsLogo.url} alt="" aria-hidden className={`inline-flex shrink-0 object-cover ${sizeClass} ${className}`} />;
    }

    return (
        <span
            className={`inline-flex shrink-0 items-center justify-center bg-(--site-color) font-bold leading-none ${pickReadableTextColor(snsLogo.color)} ${sizeClass} ${className}`}
            style={{ '--site-color': snsLogo.color } as CSSProperties}
            aria-hidden
        >
            {computeInitial(name)}
        </span>
    );
}
