import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { ImageGrid } from '@/components/image-grid';
import { LinkCard } from '@/components/link-card';
import { RowReactionChips, type RowReactions } from '@/components/reactions/reaction-bar';
import { Timestamp } from '@/components/timestamp';
import { dangerActionClass } from '@/components/ui/danger-link';
import { UserText } from '@/components/user-text';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import type { DiaryComment } from './types';

/** A row of its own rather than markup inside the page, so a test can render it. */
export function DiaryCommentRow({ comment, onDelete, reactions }: { comment: DiaryComment; onDelete: (id: number) => void; reactions: RowReactions }) {
    const t = useT();

    return (
        <li className="space-y-2 px-4 py-4 sm:px-5">
            {/* Flex header (not inline prose) — inline text-link inside a muted text block trips axe
                link-in-text-block; this also matches the topic/event comment header shape. */}
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
                {comment.deletable && (
                    <button type="button" onClick={() => onDelete(comment.id)} className={cn(dangerActionClass, 'shrink-0')}>
                        {t('Delete')}
                    </button>
                )}
            </div>
            <p className="whitespace-pre-wrap break-words">
                <UserText text={comment.body} />
            </p>
            <LinkCard card={comment.linkCard} className="mt-1" />
            <ImageGrid images={comment.images} variant="boxed" className="mt-1" />
            <RowReactionChips reactions={reactions} />
        </li>
    );
}
