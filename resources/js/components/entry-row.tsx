import { Link } from '@inertiajs/react';
import { Camera, MessageCircle, Users, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Avatar } from '@/components/avatar';
import { CommunityImage } from '@/components/community-image';
import { ListRow, stretchedLink } from '@/components/ui/surface';
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

type EntryCommunity = {
    name: string;
    imageUrl: string | null; // null → CommunityImage renders the neutral initial badge
};

/** The byline subject — exactly one of a member author (circular Avatar; null renders the
 *  withdrawn-member fallback) or the community itself (square CommunityImage) for rows where
 *  the community, not a member, is the subject (cross-community activity digests, where
 *  updated_at bumps on any comment and an author byline would misattribute). */
type EntrySubject =
    | { author: EntryAuthor | null; community?: never }
    | { community: EntryCommunity; author?: never };

type EntryRowProps = EntrySubject & {
    href: string;
    /** The line that identifies the entry: a title where there is one, the body itself where there is not. */
    content: ReactNode;
    /** How much of the content line to show before clamping. A body-only entry (a timeline post) has
     *  no title to stand in for it, so it gets two lines; everything else states itself in one.
     *  Deliberately not a `title | body` switch: the whole point is that the two are the same slot,
     *  and a name carrying the distinction invites a weight or size difference back into it. */
    contentLines?: 1 | 2;
    /** Plain byline text between the subject name and the date (the activity digest's Topic/Event kind). */
    bylineNote?: string;
    /** Pre-formatted date string shown in the byline. */
    date?: string;
    commentCount?: number;
    /** Timeline reply count; renders the same speech-bubble icon as comments, with a replies label. */
    replyCount?: number;
    /** Event roster size; renders a people icon + count after the comment count. */
    participantCount?: number;
    hasImages?: boolean;
    /** Two-line body lead-in under the byline (rich rows); empty string renders nothing. */
    excerpt?: string;
    /** Square photo thumbnails (all attachments) laid out under the excerpt; suppresses the
     *  has-photos camera marker since the photos themselves are the indicator. */
    thumbnails?: string[];
    /** Trailing action links. The chevron is dropped when they are present — the actions hold the
     *  right edge. */
    actions?: ReactNode;
};

/**
 * The canonical content-entry list row (diary / topic / event / timeline / activity digests): an
 * author-first social card. The byline pairs the subject image — a member's circular avatar or the
 * community's square image — with its name · note · date · counts, then the content line, an
 * optional excerpt, and an optional photo strip. Person/action rows (friend, block, message boxes)
 * keep their own layouts — this is not for them.
 *
 * Three sizes carry the hierarchy and no weight does: the content line is the largest text (16px,
 * foreground), the byline name is smaller (14px, foreground), and the meta beside it smaller and
 * muted. Weight is spent on headings and unread state alone, so a row has one thing to look at.
 * Author-first is about who leads the row, which the avatar and the byline still do; when the name
 * and the line below it were both 16/500/foreground, the row had two focal points and therefore none.
 */
export function EntryRow({ href, author, community, content, contentLines = 1, bylineNote, date, commentCount = 0, replyCount = 0, participantCount = 0, hasImages = false, excerpt, thumbnails, actions }: EntryRowProps) {
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

    // The byline subject: a member author (falling back to the withdrawn-member label + blank
    // avatar when null) or the community itself.
    const subjectName = community ? community.name : (author?.name ?? t('Withdrawn member'));
    const subjectImage = community ? (
        <CommunityImage name={community.name} src={community.imageUrl} className="size-8" textClassName="text-xs" decorative />
    ) : (
        // id === 0 renders the withdrawn blank badge, which is why the fallback passes author?.id ?? 0.
        <Avatar id={author?.id ?? 0} name={subjectName} src={author?.imageUrl ?? null} color={author?.avatarColor ?? null} size="sm" decorative />
    );

    const contentLine = (
        <p className={cn(contentLines === 2 ? 'line-clamp-2' : 'truncate', 'text-base text-foreground')}>
            <Link href={href} className={stretchedLink}>
                {content}
            </Link>
        </p>
    );

    // Byline meta (after the name): note, then date, then counts. The image is decorative — the
    // name is right beside it.
    const bylineMeta: ReactNode[] = [];
    if (bylineNote) {
        bylineMeta.push(<span key="note">{bylineNote}</span>);
    }
    if (date) {
        bylineMeta.push(<span key="date">{date}</span>);
    }
    bylineMeta.push(...counts);

    const textColumn = (
        <div className="min-w-0 flex-1">
            <div className="flex min-w-0 items-center gap-1.5">
                <span className="truncate text-sm text-foreground">{subjectName}</span>
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
