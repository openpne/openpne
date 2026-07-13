import { Link } from '@inertiajs/react';
import { Camera, MessageCircle, Users, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Avatar } from '@/components/avatar';
import { ListRow } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

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

type EntryAuthor = {
    id: number;
    name: string;
    imageUrl: string | null;
    avatarColor: string | null;
};

type EntryRowProps = {
    href: string;
    /** Leading visual (Avatar / CommunityImage / kind pill) for the legacy layout; ignored when
     *  `author` is set (that layout builds the byline avatar itself). */
    leading?: ReactNode;
    title: ReactNode;
    /** Replaces the one-line truncate title styling (e.g. a two-line clamped timeline body). */
    titleClassName?: string;
    /** Meta-line text parts joined with '·'; the first part truncates, the rest keep their width.
     *  Legacy layout only — the author-first layout builds its byline from `author` + `date`. */
    meta?: (string | false | null | undefined)[];
    commentCount?: number;
    /** Timeline reply count; renders the same speech-bubble icon as comments, with a replies label. */
    replyCount?: number;
    /** Event roster size; renders a people icon + count after the comment count. */
    participantCount?: number;
    hasImages?: boolean;
    /** Two-line body lead-in under the byline (rich rows); empty string renders nothing. */
    excerpt?: string;
    /** Author-first (social-card) layout: a byline of avatar + name · date · counts, then the title,
     *  excerpt, and photo strip. When set, `leading`/`meta` are unused. */
    author?: EntryAuthor;
    /** Pre-formatted date string shown in the byline (author-first layout). */
    date?: string;
    /** Square photo thumbnails (all attachments) laid out under the excerpt; suppresses the
     *  has-photos camera marker since the photos themselves are the indicator. */
    thumbnails?: string[];
    /** Trailing action links. Nested links are invalid inside a linked row, so with actions the
     *  title carries the link and the chevron is dropped. */
    actions?: ReactNode;
    /** Extra ListRow classes (e.g. `items-start` under a multi-line clamped title). */
    className?: string;
};

/**
 * The canonical content-entry list row (diary / topic / event / timeline / activity digests). Two
 * layouts share the count/marker machinery: pass `author` for the author-first social card (avatar
 * beside the name, photo strip under the body), or the legacy `leading` + `meta` line otherwise.
 * Person/action rows (friend, block, message boxes) keep their own layouts — this is not for them.
 */
export function EntryRow({ href, leading, title, titleClassName, meta = [], commentCount = 0, replyCount = 0, participantCount = 0, hasImages = false, excerpt, author, date, thumbnails, actions, className }: EntryRowProps) {
    const t = useT();

    // The photo strip already shows there are photos, so the camera marker only appears without it.
    const showPhotoMarker = hasImages && !(thumbnails && thumbnails.length > 0);
    const counts: ReactNode[] = [];
    if (commentCount > 0) {
        counts.push(<CountBadge key="comments" icon={MessageCircle} count={commentCount} srLabel={t(':count comments', { count: commentCount })} />);
    }
    if (replyCount > 0) {
        counts.push(<CountBadge key="replies" icon={MessageCircle} count={replyCount} srLabel={t(':count replies', { count: replyCount })} />);
    }
    if (participantCount > 0) {
        counts.push(<CountBadge key="participants" icon={Users} count={participantCount} srLabel={t(':count participants', { count: participantCount })} />);
    }
    if (showPhotoMarker) {
        counts.push(
            <span key="photos" className="flex shrink-0 items-center">
                <Camera className="size-3.5" aria-hidden />
                <span className="sr-only">{t('This entry has photos')}</span>
            </span>,
        );
    }

    const titleLine = (
        <p className={titleClassName ?? 'truncate font-medium text-foreground'}>
            {actions ? (
                <Link href={href} className="hover:underline">
                    {title}
                </Link>
            ) : (
                title
            )}
        </p>
    );

    if (author) {
        // Byline: avatar (decorative — the name is right beside it) then name · date · counts.
        const bylineMeta: ReactNode[] = [];
        if (date) {
            bylineMeta.push(<span key="date">{date}</span>);
        }
        bylineMeta.push(...counts);

        const content = (
            <div className="min-w-0 flex-1">
                <div className="flex min-w-0 items-center gap-1.5">
                    <span className="truncate font-medium text-foreground">{author.name}</span>
                    {bylineMeta.length > 0 && (
                        <span className="flex shrink-0 items-center gap-1.5 text-xs text-muted-foreground">
                            {bylineMeta.flatMap((item, index) => [
                                <span key={`sep-${index}`} aria-hidden>
                                    ·
                                </span>,
                                item,
                            ])}
                        </span>
                    )}
                </div>
                <div className="mt-0.5">{titleLine}</div>
                {excerpt && <p className="mt-0.5 line-clamp-2 text-sm text-muted-foreground">{excerpt}</p>}
                {thumbnails && thumbnails.length > 0 && (
                    <div className="mt-2 flex gap-1.5">
                        {thumbnails.map((src, index) => (
                            <img key={index} src={src} alt="" aria-hidden className="size-16 rounded object-cover" loading="lazy" />
                        ))}
                    </div>
                )}
            </div>
        );

        const avatar = <Avatar id={author.id} name={author.name} src={author.imageUrl} color={author.avatarColor} size="sm" decorative />;

        if (actions) {
            return (
                <ListRow className={cn('items-start', className)}>
                    {avatar}
                    {content}
                    <span className="flex shrink-0 items-center gap-3 text-sm">{actions}</span>
                </ListRow>
            );
        }

        return (
            <ListRow href={href} chevron className={cn('items-start', className)}>
                {avatar}
                {content}
            </ListRow>
        );
    }

    const items: ReactNode[] = meta
        .filter((part): part is string => typeof part === 'string' && part !== '')
        .map((part, index) => (
            <span key={`meta-${index}`} className={index === 0 ? 'truncate' : 'shrink-0'}>
                {part}
            </span>
        ));
    items.push(...counts);

    const body = (
        <div className="min-w-0 flex-1">
            {titleLine}
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
            {excerpt && <p className="mt-0.5 line-clamp-2 text-sm text-muted-foreground">{excerpt}</p>}
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
