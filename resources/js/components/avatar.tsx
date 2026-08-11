import { InitialBadge } from '@/components/initial-badge';

/**
 * Circular member avatar. Renders the image when `src` is set, otherwise a neutral initial badge.
 * The circle shape distinguishes it from a community image.
 *
 * Size follows the avatar's role, not the surrounding font size. `md` (40px) is for the person a
 * piece of content or a row is *about* — entry and comment authors, a message's single
 * counterparty, member / message box / notification rows — where the avatar is the primary
 * identification cue. `sm` (32px) is for app chrome and dense pickers (own avatar, scope identity,
 * mention candidates, compose recipient chips, a multi-recipient list, settings rows), where it is
 * not the reason the row exists. `lg` (48px) is for roster grids.
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
    /** Member id. Pass `0` (e.g. `author?.id ?? 0`) for a withdrawn member so it renders a blank
     *  badge — the absent initial is what tells it apart from a member who set no image. */
    id: number;
    name: string;
    /** Image URL, or null to fall back to the initial badge. */
    src: string | null;
    /** The member's chosen badge color (hex), or null for the neutral badge. Required so a call
     *  site that forgets to thread it through fails type-check instead of silently graying out. */
    color: string | null;
    size?: AvatarSize;
    /** Set when the name is already shown as adjacent text (list rows, rosters): the avatar becomes
     *  decorative so it isn't announced twice (avoids the image-redundant-alt a11y warning). */
    decorative?: boolean;
};

export function Avatar({ id, name, src, color, size = 'md', decorative = false }: Props) {
    const baseCls = `${sizeClass[size]} shrink-0 rounded-full`;
    // Decorative: hide from the a11y tree (the adjacent text names the member). Otherwise expose the
    // name via alt / aria-label so a standalone avatar still has an accessible name.
    const semantics = decorative ? { 'aria-hidden': true } : { role: 'img', 'aria-label': name };

    if (src) {
        return <img src={src} alt={decorative ? '' : name} className={`${baseCls} object-cover`} />;
    }

    if (id === 0) {
        // Withdrawn members stay a blank neutral circle no matter what color data arrives.
        // `<span>` is inline by default, so `size-*` needs `inline-block` to take effect.
        return <span className={`${baseCls} inline-block bg-muted`} {...semantics} />;
    }

    return <InitialBadge name={name} color={color} className={`${baseCls} ${textSizeClass[size]}`} {...semantics} />;
}
