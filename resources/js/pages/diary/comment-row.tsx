import { Trash2 } from 'lucide-react';
import { useRef } from 'react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { ImageGrid } from '@/components/image-grid';
import { LinkCard } from '@/components/link-card';
import { ICON_BUTTON, ReactionAdd, RowReactionChips, type RowReactions } from '@/components/reactions/reaction-bar';
import { PRESS_ROW, REVEAL_ROW, RevealBar } from '@/components/row/reveal-bar';
import { Timestamp } from '@/components/timestamp';
import { Tip } from '@/components/ui/tooltip';
import { UserText } from '@/components/user-text';
import { useT } from '@/lib/i18n';
import { useLongPress } from '@/lib/use-long-press';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import type { DiaryComment } from './types';

/** A row of its own rather than markup inside the page, so a test can render it. */
export function DiaryCommentRow({
    comment,
    onDelete,
    reactions,
    onOpenActions,
}: {
    comment: DiaryComment;
    onDelete: (id: number) => void;
    reactions: RowReactions;
    onOpenActions?: (row: HTMLElement) => void;
}) {
    const t = useT();
    const row = useRef<HTMLLIElement>(null);
    const press = useLongPress(() => onOpenActions?.(row.current!), { enabled: onOpenActions !== undefined });

    return (
        <li ref={row} tabIndex={-1} {...press} className={cn(REVEAL_ROW, PRESS_ROW, 'px-4 py-4 outline-none sm:px-5')}>
            {(reactions.onToggle !== undefined || comment.deletable) && (
                <RevealBar>
                    {reactions.onToggle !== undefined && <ReactionAdd chips={reactions.chips} vocabulary={reactions.vocabulary} onPick={reactions.onToggle} />}
                    {comment.deletable && (
                        <Tip label={t('Delete')}>
                            <button type="button" onClick={() => onDelete(comment.id)} className={cn(ICON_BUTTON, 'hover:bg-destructive/10 hover:text-destructive')}>
                                <Trash2 className="size-4" aria-hidden />
                            </button>
                        </Tip>
                    )}
                </RevealBar>
            )}
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Avatar id={comment.author?.id ?? 0} name={comment.author?.name ?? ''} src={comment.author?.imageUrl ?? null} color={comment.author?.avatarColor ?? null} isAi={comment.author?.isAi ?? false} size="md" decorative />
                {comment.author ? (
                    <Link href={`/member/${comment.author.id}`} className="truncate text-link hover:underline">
                        {comment.author.name}
                    </Link>
                ) : (
                    <span className="truncate">{t('Withdrawn member')}</span>
                )}
                <AiChip isAi={comment.author?.isAi ?? false} />
                <span className="ml-auto shrink-0">#{comment.number}</span>
                <Timestamp at={comment.createdAt} preset="relative" className="shrink-0" />
            </div>
            <p className="mt-1 whitespace-pre-wrap break-words">
                <UserText text={comment.body} />
            </p>
            <LinkCard card={comment.linkCard} className="mt-1" />
            <ImageGrid images={comment.images} variant="boxed" className="mt-1" />
            <RowReactionChips reactions={reactions} />
        </li>
    );
}
