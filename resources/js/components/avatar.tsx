import { computeInitial, pickPaletteColor, pickReadableTextColor } from '@/lib/identity-mark';

/**
 * Circular member avatar. Renders the image when `src` is set, otherwise an initial badge colored
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
    /** Member id, hashed to the badge color. Pass `0` (e.g. `author?.id ?? 0`) for a withdrawn
     *  member so it renders a neutral placeholder instead of a colored badge. */
    id: number;
    name: string;
    /** Image URL, or null to fall back to the initial badge. */
    src: string | null;
    size?: AvatarSize;
    /** Set when the name is already shown as adjacent text (list rows, rosters): the avatar becomes
     *  decorative so it isn't announced twice (avoids the image-redundant-alt a11y warning). */
    decorative?: boolean;
};

export function Avatar({ id, name, src, size = 'md', decorative = false }: Props) {
    const baseCls = `${sizeClass[size]} shrink-0 rounded-full`;
    // Decorative: hide from the a11y tree (the adjacent text names the member). Otherwise expose the
    // name via alt / aria-label so a standalone avatar still has an accessible name.
    const semantics = decorative ? { 'aria-hidden': true } : { role: 'img', 'aria-label': name };

    if (src) {
        return <img src={src} alt={decorative ? '' : name} className={`${baseCls} object-cover`} />;
    }

    if (id === 0) {
        // `<span>` is inline by default, so `size-*` needs `inline-block` to take effect.
        return <span className={`${baseCls} inline-block bg-muted`} {...semantics} />;
    }

    const bgColor = pickPaletteColor(id);
    const textColorClass = pickReadableTextColor(bgColor);

    return (
        <span
            className={`${baseCls} inline-flex items-center justify-center font-bold leading-none ${textColorClass} ${textSizeClass[size]}`}
            style={{ backgroundColor: bgColor }}
            {...semantics}
        >
            {computeInitial(name)}
        </span>
    );
}
