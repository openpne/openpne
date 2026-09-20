import { AiChip } from '@/components/ai-chip';
import { LinkCard } from '@/components/link-card';
import { Link, router } from '@inertiajs/react';
import { MessageCircle, Trash2 } from 'lucide-react';
import { useRef } from 'react';
import { ImageGrid } from '@/components/image-grid';
import { Avatar } from '@/components/avatar';
import { useConfirm } from '@/components/confirm-dialog';
import { CountBadge } from '@/components/entry-row';
import { Timestamp } from '@/components/timestamp';
import { EntityText } from '@/components/entity-text';
import { ICON_BUTTON, ReactionAdd, RowReactionChips, type RowReactions } from '@/components/reactions/reaction-bar';
import { PRESS_ROW, REVEAL_ROW, RevealBar } from '@/components/row/reveal-bar';
import { rowSheetOpens } from '@/components/row/row-sheet';
import { Tip } from '@/components/ui/tooltip';
import { repliesPhrase } from '@/lib/count-phrase';
import { useT } from '@/lib/i18n';
import { useLongPress } from '@/lib/use-long-press';
import { cn } from '@/lib/utils';
import type { TimelinePostEntry } from './types';

interface TimelinePostCardProps {
    post: TimelinePostEntry;
    viewerId: number;
    reactions: RowReactions;
    /** Absent where the page raises no sheet; given, a press hands over the row for focus to return to. */
    onOpenActions?: (row: HTMLElement) => void;
}

/** One question and one route for a post, whether asked from the card's bar or the page's sheet. */
export function useDeleteTimelinePost() {
    const t = useT();
    const confirm = useConfirm();

    return async (id: number, opener: HTMLElement | null = null) => {
        if (await confirm({ title: t('Delete this post?'), confirmLabel: t('Delete'), danger: true, opener })) {
            router.post(`/timeline/delete/${id}`);
        }
    };
}

export function TimelinePostCard({ post, viewerId, reactions, onOpenActions }: TimelinePostCardProps) {
    const t = useT();
    const row = useRef<HTMLLIElement>(null);
    const isOwn = post.author.id === viewerId;
    const press = useLongPress(() => onOpenActions?.(row.current!), {
        enabled:
            onOpenActions !== undefined &&
            rowSheetOpens({ body: post.body, chips: reactions.chips, canReact: reactions.onToggle !== undefined, onShowReactors: reactions.onShowReactors, onDelete: isOwn ? () => {} : undefined, link: () => '' }),
    });
    const deletePost = useDeleteTimelinePost();

    return (
        <li ref={row} tabIndex={-1} {...press} className={cn(REVEAL_ROW, PRESS_ROW, 'space-y-2 px-4 py-4 text-foreground outline-none sm:px-5')}>
            {((reactions.onToggle !== undefined && reactions.chips.length === 0) || isOwn) && (
                <RevealBar>
                    {/* With chips the add button stands at their end; two of the same name on one row would be one too many. */}
                    {reactions.onToggle !== undefined && reactions.chips.length === 0 && <ReactionAdd chips={reactions.chips} vocabulary={reactions.vocabulary} onPick={reactions.onToggle} />}
                    {isOwn && (
                        <Tip label={t('Delete')}>
                            <button type="button" onClick={() => void deletePost(post.id)} className={cn(ICON_BUTTON, 'hover:bg-destructive/10 hover:text-destructive')}>
                                <Trash2 className="size-4" aria-hidden />
                            </button>
                        </Tip>
                    )}
                </RevealBar>
            )}
            <div className="flex items-center justify-between gap-3 text-sm">
                <div className="flex min-w-0 items-center gap-2">
                    <Link href={`/member/${post.author.id}/timeline`} className="flex min-w-0 items-center gap-2 text-link hover:underline">
                        <Avatar id={post.author.id} name={post.author.name} src={post.author.imageUrl} color={post.author.avatarColor} isAi={post.author.isAi} size="md" decorative />
                        <span className="truncate">{post.author.name}</span>
                    </Link>
                    <AiChip isAi={post.author.isAi} />
                </div>
                <div className="flex shrink-0 items-center gap-2 text-muted-foreground">
                    <CountBadge icon={MessageCircle} count={post.replyCount} srLabel={repliesPhrase(t, post.replyCount)} />
                    <Link href={`/timeline/${post.id}`} className="hover:text-foreground hover:underline">
                        <Timestamp at={post.createdAt} preset="relative" />
                    </Link>
                </div>
            </div>
            <p className="whitespace-pre-wrap break-words">
                <EntityText text={post.body} mentions={post.mentions} tags={post.tags} />
            </p>
            <LinkCard card={post.linkCard} />
            <ImageGrid images={post.images} variant="post" />
            <RowReactionChips reactions={reactions} />
        </li>
    );
}
