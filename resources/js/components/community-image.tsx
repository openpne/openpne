import { InitialBadge } from '@/components/initial-badge';

/**
 * Square community image. Renders the image when `src` is set, otherwise a neutral initial badge.
 * The rounded-square shape distinguishes a community (place) from the circular member Avatar
 * (person).
 */
type Props = {
    name: string;
    /** Image URL, or null to fall back to the initial badge. */
    src: string | null;
    /** Outer size classes (e.g. `size-14`, `w-full aspect-square`). */
    className?: string;
    /** Badge font size when falling back to the initial. */
    textClassName?: string;
    /** Set when the name is already shown as adjacent text (list rows, tiles): the image becomes
     *  decorative so it isn't announced twice (avoids the image-redundant-alt a11y warning). */
    decorative?: boolean;
};

export function CommunityImage({ name, src, className = 'size-14', textClassName = 'text-xl', decorative = false }: Props) {
    const base = `${className} shrink-0 rounded-lg`;
    const semantics = decorative ? { 'aria-hidden': true } : { role: 'img', 'aria-label': name };

    if (src) {
        return <img src={src} alt={decorative ? '' : name} className={`${base} object-cover`} />;
    }

    return <InitialBadge name={name} className={`${base} ${textClassName}`} {...semantics} />;
}
