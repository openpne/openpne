import { usePage } from '@inertiajs/react';
import { computeInitial, pickReadableTextColor } from '@/lib/identity-mark';
import type { PageProps } from '@/types';

/**
 * SNS brand mark: the admin logo image when set, else the SNS name's initial on the configured
 * color badge (WCAG-aware text color). Shares the initial/contrast helpers with Avatar.
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
            className={`inline-flex shrink-0 items-center justify-center font-bold leading-none ${pickReadableTextColor(snsLogo.color)} ${sizeClass} ${className}`}
            style={{ backgroundColor: snsLogo.color }}
            aria-hidden
        >
            {computeInitial(name)}
        </span>
    );
}
