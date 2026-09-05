import { Link } from '@inertiajs/react';
import { Camera, MessageCircle, Users, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { CommunityImage } from '@/components/community-image';
import { ListRow, stretchedLink } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** Renders nothing at zero. */
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
    isAi: boolean;
};

type EntryCommunity = {
    name: string;
    imageUrl: string | null; // null → CommunityImage renders the neutral initial badge
};

/** The byline subject — exactly one of a member author (circular Avatar; null renders the
 *  withdrawn-member fallback) or the group itself (square CommunityImage) for rows where
 *  the group, not a member, is the subject (cross-community activity digests, where
 *  updated_at bumps on any comment and an author byline would misattribute). */
type EntrySubject =
    | { author: EntryAuthor | null; group?: never }
    | { group: EntryCommunity; author?: never };

type EntryRowProps = EntrySubject & {
    href: string;
    /**
     * The line that identifies the entry: a title where there is one, the body itself where there
     * is not.
     */
    content: ReactNode;
    /** Deliberately not a `title | body` switch: the two are the same slot, and a name carrying the
     *  distinction invites a weight or size difference back into it. */
    contentLines?: 1 | 2;
    bylineNote?: string;
    date?: ReactNode;
    commentCount?: number;
    replyCount?: number;
    participantCount?: number;
    hasImages?: boolean;
    /** An empty string renders nothing. */
    excerpt?: string;
    /** Suppresses the has-photos camera marker: the photos themselves are the indicator. */
    thumbnails?: string[];
    /** The chevron is dropped when these are present — the actions hold the right edge. */
    actions?: ReactNode;
};

/**
 * The content-entry list row (diary / topic / event / timeline / activity digests). Person and action
 * rows — friend, block, message boxes — keep their own layouts and are not this.
 */
export function EntryRow({ href, author, group, content, contentLines = 1, bylineNote, date, commentCount = 0, replyCount = 0, participantCount = 0, hasImages = false, excerpt, thumbnails, actions }: EntryRowProps) {
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

    const subjectName = group ? group.name : (author?.name ?? t('Withdrawn member'));
    const subjectImage = group ? (
        // Matches the Avatar's md size — the two alternate in this one byline slot.
        <CommunityImage name={group.name} src={group.imageUrl} className="size-10" textClassName="text-sm" decorative />
    ) : (
        // `id === 0` renders the withdrawn blank badge, which is why the fallback passes `author?.id ?? 0`.
        <Avatar id={author?.id ?? 0} name={subjectName} src={author?.imageUrl ?? null} color={author?.avatarColor ?? null} isAi={author?.isAi ?? false} size="md" decorative />
    );

    const contentLine = (
        <p className={cn(contentLines === 2 ? 'line-clamp-2' : 'truncate', 'text-base text-foreground')}>
            <Link href={href} className={stretchedLink}>
                {content}
            </Link>
        </p>
    );

    // The image is decorative here — the name is right beside it.
    const bylineMeta: ReactNode[] = [];
    if (bylineNote) {
        bylineMeta.push(<span key="note">{bylineNote}</span>);
    }
    if (date) {
        bylineMeta.push(<span key="date">{date}</span>);
    }
    bylineMeta.push(...counts);

    // The byline holds a line's height whatever it carries, and the row aligns to its top, so the
    // content line sits the same distance from the row top in every row of a list.
    const textColumn = (
        <div className="min-w-0 flex-1">
            <div className="flex min-h-5 min-w-0 items-center gap-1.5">
                <span className="truncate text-sm text-foreground">{subjectName}</span>
                <AiChip isAi={author?.isAi ?? false} />
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
            <div className="mt-0.5">{contentLine}</div>
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

    if (actions) {
        return (
            <ListRow rowLink className="items-start">
                {subjectImage}
                {textColumn}
                {/* Raised above the row link, but only the actions themselves take the clicks: the
                    gaps between them stay transparent, so they open the entry like the rest of the row. */}
                <span className="pointer-events-none relative z-10 flex shrink-0 items-center gap-3 text-sm [&>*]:pointer-events-auto">{actions}</span>
            </ListRow>
        );
    }

    return (
        <ListRow rowLink chevron className="items-start">
            {subjectImage}
            {textColumn}
        </ListRow>
    );
}
