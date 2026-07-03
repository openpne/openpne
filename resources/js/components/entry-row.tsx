import { Link } from '@inertiajs/react';
import { Camera, MessageCircle, Users, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { ListRow } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';

/** Icon + count meta cluster for list rows. Renders nothing at zero. */
export function CountBadge({ icon: Icon, count, srLabel }: { icon: LucideIcon; count: number; srLabel: string }) {
    if (count <= 0) {
        return null;
    }
    return (
        <span className="flex shrink-0 items-center gap-0.5">
            <Icon className="size-3.5" aria-hidden />
            <span aria-hidden>{count}</span>
            <span className="sr-only">{srLabel}</span>
        </span>
    );
}

type EntryRowProps = {
    href: string;
    /** Leading visual (Avatar / CommunityImage / kind pill); omit on single-member archives. */
    leading?: ReactNode;
    title: ReactNode;
    /** Replaces the one-line truncate title styling (e.g. a two-line clamped timeline body). */
    titleClassName?: string;
    /** Meta-line text parts joined with '·'; the first part truncates, the rest keep their width. */
    meta?: (string | false | null | undefined)[];
    commentCount?: number;
    /** Timeline reply count; renders the same speech-bubble icon as comments, with a replies label. */
    replyCount?: number;
    /** Event roster size; renders a people icon + count after the comment count. */
    participantCount?: number;
    hasImages?: boolean;
    /** Trailing action links. Nested links are invalid inside a linked row, so with actions the
     *  title carries the link and the chevron is dropped. */
    actions?: ReactNode;
    /** Extra ListRow classes (e.g. `items-start` under a multi-line clamped title). */
    className?: string;
};

/**
 * The canonical content-entry list row (diary / topic / event / timeline / activity digests):
 * leading visual + title line + one meta line of author · date · counts. Person/action rows
 * (friend, block, message boxes) keep their own layouts — this is not for them.
 */
export function EntryRow({ href, leading, title, titleClassName, meta = [], commentCount = 0, replyCount = 0, participantCount = 0, hasImages = false, actions, className }: EntryRowProps) {
    const t = useT();

    const items: ReactNode[] = meta
        .filter((part): part is string => typeof part === 'string' && part !== '')
        .map((part, index) => (
            <span key={`meta-${index}`} className={index === 0 ? 'truncate' : 'shrink-0'}>
                {part}
            </span>
        ));
    if (commentCount > 0) {
        items.push(
            <CountBadge key="comments" icon={MessageCircle} count={commentCount} srLabel={t(':count comments', { count: commentCount })} />,
        );
    }
    if (replyCount > 0) {
        items.push(
            <CountBadge key="replies" icon={MessageCircle} count={replyCount} srLabel={t(':count replies', { count: replyCount })} />,
        );
    }
    if (participantCount > 0) {
        items.push(
            <CountBadge key="participants" icon={Users} count={participantCount} srLabel={t(':count participants', { count: participantCount })} />,
        );
    }
    if (hasImages) {
        items.push(
            <span key="photos" className="flex shrink-0 items-center">
                <Camera className="size-3.5" aria-hidden />
                <span className="sr-only">{t('This entry has photos')}</span>
            </span>,
        );
    }

    const body = (
        <div className="min-w-0 flex-1">
            <p className={titleClassName ?? 'truncate font-medium text-foreground'}>
                {actions ? (
                    <Link href={href} className="hover:underline">
                        {title}
                    </Link>
                ) : (
                    title
                )}
            </p>
            {items.length > 0 && (
                <p className="mt-0.5 flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground">
                    {items.flatMap((item, index) =>
                        index === 0
                            ? [item]
                            : [
                                  <span key={`sep-${index}`} aria-hidden>
                                      ·
                                  </span>,
                                  item,
                              ],
                    )}
                </p>
            )}
        </div>
    );

    if (actions) {
        return (
            <ListRow className={className}>
                {leading}
                {body}
                <span className="flex shrink-0 items-center gap-3 text-sm">{actions}</span>
            </ListRow>
        );
    }

    return (
        <ListRow href={href} chevron className={className}>
            {leading}
            {body}
        </ListRow>
    );
}
