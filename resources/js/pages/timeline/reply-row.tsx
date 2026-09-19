import { EntityText } from '@/components/entity-text';
import { LinkCard } from '@/components/link-card';
import type { RowReactions } from '@/components/reactions/reaction-bar';
import { RowBody } from '@/components/row/row-body';
import { reactorsItem, RowMenu } from '@/components/row/row-menu';
import { Timestamp } from '@/components/timestamp';
import { useT } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import type { TimelinePostEntry } from './types';

/**
 * A row of its own rather than markup inside the page, so a test can render it: a payload assertion
 * cannot see whether a reply's link card is drawn.
 */
export function TimelineReplyRow({ reply, viewerId, onDelete, reactions }: { reply: TimelinePostEntry; viewerId: number; onDelete: (id: number) => void; reactions: RowReactions }) {
    const t = useT();

    return (
        <li className="space-y-1 px-4 py-3 sm:px-5">
            <div className="flex items-center justify-between gap-2 text-sm">
                <Link href={`/member/${reply.author.id}/timeline`} className="truncate text-link hover:underline">
                    {reply.author.name}
                </Link>
                <div className="flex shrink-0 items-center gap-2 text-muted-foreground">
                    <Timestamp at={reply.createdAt} preset="relative" />
                    <RowMenu items={[reactorsItem(t, reactions), reply.author.id === viewerId ? { label: t('Delete'), icon: Trash2, destructive: true, onSelect: () => onDelete(reply.id) } : null]} />
                </div>
            </div>
            <RowBody reactions={reactions} contentClassName="space-y-1">
                <p className="whitespace-pre-wrap break-words">
                    <EntityText text={reply.body} mentions={reply.mentions} tags={reply.tags} />
                </p>
                <LinkCard card={reply.linkCard} />
            </RowBody>
        </li>
    );
}
