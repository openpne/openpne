import { AiCornerMark } from '@/components/ai-corner-mark';
import { InitialBadge } from '@/components/initial-badge';
import { markedName } from '@/lib/identity-mark';
import { useT } from '@/lib/i18n';

/**
 * Circular member avatar. Renders the image when `src` is set, otherwise a neutral initial badge.
 * The circle shape distinguishes it from a group image.
 *
 * Size follows the avatar's role, not the surrounding font size. `md` (40px) is for the person a
 * piece of content or a row is *about* — entry and comment authors, a message's single
 * counterparty, member / message box / notification rows — where the avatar is the primary
 * identification cue. `sm` (32px) is for app chrome and dense pickers (own avatar, scope identity,
 * mention candidates, compose recipient chips, a multi-recipient list, settings rows), where it is
 * not the reason the row exists. `lg` (48px) is for roster grids. `xs` (24px) is for a face inside a
 * pill of furniture (the desktop place bar), where the pill's own height is what sets the face.
 */
export type AvatarSize = 'xs' | 'sm' | 'md' | 'lg';

const sizeClass: Record<AvatarSize, string> = {
    xs: 'size-6',
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
    /** Whether this is an AI account. Required, and `false` for a withdrawn author: an avatar is
     *  drawn in places that name nobody (roster grids, the right rail), so the fact has to travel
     *  with the face rather than only with the {@link AiChip} beside a name. */
    isAi: boolean;
    size?: AvatarSize;
    /** Set when the name is already shown as adjacent text (list rows, rosters): the avatar becomes
     *  decorative so it isn't announced twice (avoids the image-redundant-alt a11y warning). */
    decorative?: boolean;
};

export function Avatar({ id, name, src, color, isAi, size = 'md', decorative = false }: Props) {
    const t = useT();
    const baseCls = `${sizeClass[size]} shrink-0 rounded-full`;
    // A standalone avatar's accessible name carries the AI fact, because nothing beside it does.
    // A decorative one stays silent: the AiChip next to the name already says it, once.
    const label = markedName(name, isAi, t);
    // Decorative: hide from the a11y tree (the adjacent text names the member). Otherwise expose the
    // name via alt / aria-label so a standalone avatar still has an accessible name.
    const semantics = decorative ? { 'aria-hidden': true } : { role: 'img', 'aria-label': label };

    let face;
    if (src) {
        face = <img src={src} alt={decorative ? '' : label} className={`${baseCls} object-cover`} />;
    } else if (id === 0) {
        // Withdrawn members stay a blank neutral circle no matter what color data arrives.
        // `<span>` is inline by default, so `size-*` needs `inline-block` to take effect.
        face = <span className={`${baseCls} inline-block bg-muted`} {...semantics} />;
    } else {
        face = <InitialBadge name={name} color={color} className={`${baseCls} ${textSizeClass[size]}`} {...semantics} />;
    }

    if (!isAi) {
        return face;
    }

    return (
        // inline-flex + shrink-0 so the wrapper stands where the bare face used to in a flex row.
        <span className="relative inline-flex shrink-0">
            {face}
            <AiCornerMark isAi={isAi} size={size} />
        </span>
    );
}
