import { AiCornerMark } from '@/components/ai-corner-mark';
import { InitialBadge } from '@/components/initial-badge';
import { markedName } from '@/lib/identity-mark';
import { useT } from '@/lib/i18n';

/**
 * The circle shape is what distinguishes a member from a group image. Size follows the avatar's
 * role — how much of the row's identification it carries — not the surrounding font size.
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
    /** Pass `0` for a withdrawn member: the blank badge's absent initial is what tells it apart from
     *  a member who set no image. */
    id: number;
    name: string;
    src: string | null;
    /** Required, so a call site that forgets to thread it through fails type-check instead of
     *  silently graying out. */
    color: string | null;
    /** Required, and `false` for a withdrawn author: an avatar is drawn where nothing else names the
     *  member, so the fact travels with the face. */
    isAi: boolean;
    size?: AvatarSize;
    /** Set when the name is already shown as adjacent text (list rows, rosters): the avatar becomes
     *  decorative so it isn't announced twice (avoids the image-redundant-alt a11y warning). */
    decorative?: boolean;
};

export function Avatar({ id, name, src, color, isAi, size = 'md', decorative = false }: Props) {
    const t = useT();
    const baseCls = `${sizeClass[size]} shrink-0 rounded-full`;
    // A standalone avatar's name carries the AI fact because nothing beside it does; a decorative one
    // stays silent, the AiChip beside the name having said it once.
    const label = markedName(name, isAi, t);
    const semantics = decorative ? { 'aria-hidden': true } : { role: 'img', 'aria-label': label };

    let face;
    if (src) {
        face = <img src={src} alt={decorative ? '' : label} className={`${baseCls} object-cover`} />;
    } else if (id === 0) {
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
