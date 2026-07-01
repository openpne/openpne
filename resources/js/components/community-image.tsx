import { computeInitial, pickPaletteColor, pickReadableTextColor } from '@/lib/identity-mark';

/**
 * Square community image. Renders the image when `src` is set, otherwise an initial badge colored
 * by community id (stable across renames). The rounded-square shape distinguishes a community
 * (place) from the circular member Avatar (person).
 */
type Props = {
    /** Community id, hashed to the badge color. */
    id: number;
    name: string;
    /** Image URL, or null to fall back to the initial badge. */
    src: string | null;
    /** Outer size classes (e.g. `size-14`, `w-full aspect-square`). */
    className?: string;
    /** Badge font size when falling back to the initial. */
    textClassName?: string;
};

export function CommunityImage({ id, name, src, className = 'size-14', textClassName = 'text-xl' }: Props) {
    const base = `${className} shrink-0 rounded-lg`;

    if (src) {
        return <img src={src} alt={name} className={`${base} object-cover`} />;
    }

    const bgColor = pickPaletteColor(id);
    const textColorClass = pickReadableTextColor(bgColor);

    return (
        <span
            className={`${base} inline-flex items-center justify-center font-bold leading-none ${textColorClass} ${textClassName}`}
            style={{ backgroundColor: bgColor }}
            role="img"
            aria-label={name}
        >
            {computeInitial(name)}
        </span>
    );
}
