import { EntityText } from '@/components/entity-text';
import { LinkCard } from '@/components/link-card';
import { ReactionAdd, ReactionChips, type RowReactions } from '@/components/reactions/reaction-bar';
import { Timestamp } from '@/components/timestamp';
import { dangerActionClass } from '@/components/ui/danger-link';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import type { TimelinePostEntry } from './types';

/**
 * A row of its own rather than markup inside the page, so a test can render it: a payload assertion
 * cannot see whether a reply's link card is drawn.
 */
export function TimelineReplyRow({ reply, viewerId, onDelete, reactions }: { reply: TimelinePostEntry; viewerId: number; onDelete: (id: number) => void; reactions: RowReactions }) {
    const t = useT();

    return (
        <li className="space-y-1 px-4 py-3 sm:px-5">
            <div className="flex items-center justify-between text-sm">
                <Link href={`/member/${reply.author.id}/timeline`} className="text-link hover:underline">
                    {reply.author.name}
                </Link>
                <div className="flex shrink-0 items-center gap-2 text-muted-foreground">
                    <ReactionAdd chips={reactions.chips} vocabulary={reactions.vocabulary} onPick={reactions.onToggle} />
                    <Timestamp at={reply.createdAt} preset="relative" />
                </div>
            </div>
            <p className="whitespace-pre-wrap break-words">
                <EntityText text={reply.body} mentions={reply.mentions} tags={reply.tags} />
            </p>
            <LinkCard card={reply.linkCard} />
            <ReactionChips chips={reactions.chips} onToggle={reactions.onToggle} onShowReactors={reactions.onShowReactors} />
            {reply.author.id === viewerId && (
                <button type="button" onClick={() => onDelete(reply.id)} className={cn(dangerActionClass, 'text-sm')}>
                    {t('Delete')}
                </button>
            )}
        </li>
    );
}
