import { Trash2 } from 'lucide-react';
import { useRef } from 'react';
import { EntityText } from '@/components/entity-text';
import { LinkCard } from '@/components/link-card';
import { ICON_BUTTON, ReactionAdd, RowReactionChips, type RowReactions } from '@/components/reactions/reaction-bar';
import { PRESS_ROW, REVEAL_ROW, RevealBar } from '@/components/row/reveal-bar';
import { Timestamp } from '@/components/timestamp';
import { Tip } from '@/components/ui/tooltip';
import { useT } from '@/lib/i18n';
import { useLongPress } from '@/lib/use-long-press';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import type { TimelinePostEntry } from './types';

/**
 * A row of its own rather than markup inside the page, so a test can render it: a payload assertion
 * cannot see whether a reply's link card is drawn.
 */
export function TimelineReplyRow({
    reply,
    viewerId,
    onDelete,
    reactions,
    onOpenActions,
}: {
    reply: TimelinePostEntry;
    viewerId: number;
    onDelete: (id: number) => void;
    reactions: RowReactions;
    onOpenActions?: (row: HTMLElement) => void;
}) {
    const t = useT();
    const row = useRef<HTMLLIElement>(null);
    const isOwn = reply.author.id === viewerId;
    const press = useLongPress(() => onOpenActions?.(row.current!), { enabled: onOpenActions !== undefined });

    return (
        <li ref={row} tabIndex={-1} {...press} className={cn(REVEAL_ROW, PRESS_ROW, 'space-y-1 px-4 py-3 outline-none sm:px-5')}>
            {(reactions.onToggle !== undefined || isOwn) && (
                <RevealBar>
                    {reactions.onToggle !== undefined && <ReactionAdd chips={reactions.chips} vocabulary={reactions.vocabulary} onPick={reactions.onToggle} />}
                    {isOwn && (
                        <Tip label={t('Delete')}>
                            <button type="button" onClick={() => onDelete(reply.id)} className={cn(ICON_BUTTON, 'hover:bg-destructive/10 hover:text-destructive')}>
                                <Trash2 className="size-4" aria-hidden />
                            </button>
                        </Tip>
                    )}
                </RevealBar>
            )}
            <div className="flex items-center justify-between text-sm">
                <Link href={`/member/${reply.author.id}/timeline`} className="text-link hover:underline">
                    {reply.author.name}
                </Link>
                <Timestamp at={reply.createdAt} preset="relative" className="text-muted-foreground" />
            </div>
            <p className="whitespace-pre-wrap break-words">
                <EntityText text={reply.body} mentions={reply.mentions} tags={reply.tags} />
            </p>
            <LinkCard card={reply.linkCard} />
            <RowReactionChips reactions={reactions} />
        </li>
    );
}
