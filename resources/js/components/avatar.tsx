import { computeInitial, pickPaletteColor, pickReadableTextColor } from '@/lib/identity-mark';

/**
 * Circular member avatar. Renders the image when `src` is set, otherwise an initial badge coloured
 * by member id (stable across renames). The circle shape distinguishes it from a community image.
 */
export type AvatarSize = 'xs' | 'sm' | 'md' | 'lg';

const sizeClass: Record<AvatarSize, string> = {
    xs: 'size-7',
    sm: 'size-8',
    md: 'size-10',
    lg: 'size-12',
};

const textSizeClass: Record<AvatarSize, string> = {
    xs: 'text-[10px]',
    sm: 'text-xs',
    md: 'text-sm',
    lg: 'text-base',
};

type Props = {
    /** Member id, hashed to the badge colour. Pass `0` (e.g. `author?.id ?? 0`) for a withdrawn
     *  member so it renders a neutral placeholder instead of a coloured badge. */
    id: number;
    name: string;
    /** Image URL, or null to fall back to the initial badge. */
    src: string | null;
    size?: AvatarSize;
};

export function Avatar({ id, name, src, size = 'md' }: Props) {
    const baseCls = `${sizeClass[size]} shrink-0 rounded-full`;

    if (src) {
        return <img src={src} alt={name} className={`${baseCls} object-cover`} />;
    }

    if (id === 0) {
        // `<span>` is inline by default, so `size-*` needs `inline-block` to take effect.
        return <span className={`${baseCls} inline-block bg-slate-200 dark:bg-slate-700`} role="img" aria-label={name} />;
    }

    const bgColor = pickPaletteColor(id);
    const textColorClass = pickReadableTextColor(bgColor);

    return (
        <span
            className={`${baseCls} inline-flex items-center justify-center font-bold leading-none ${textColorClass} ${textSizeClass[size]}`}
            style={{ backgroundColor: bgColor }}
            role="img"
            aria-label={name}
        >
            {computeInitial(name)}
        </span>
    );
}
