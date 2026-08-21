import { EntityText } from '@/components/entity-text';
import { LinkCard } from '@/components/link-card';
import { Timestamp } from '@/components/timestamp';
import { dangerActionClass } from '@/components/ui/danger-link';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import type { TimelinePostEntry } from './types';

/**
 * One reply in a thread.
 *
 * A row of its own rather than markup inside the page, for the reason `post-card` is: it is the
 * thing a test can render. A reply carries a link card like any other body, and that was shipped
 * once with the server side wired and no card drawn — a payload assertion cannot see the difference.
 */
export function TimelineReplyRow({ reply, viewerId, onDelete }: { reply: TimelinePostEntry; viewerId: number; onDelete: (id: number) => void }) {
    const t = useT();

    return (
        <li className="space-y-1 px-4 py-3 sm:px-5">
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
            {reply.author.id === viewerId && (
                <button type="button" onClick={() => onDelete(reply.id)} className={cn(dangerActionClass, 'text-sm')}>
                    {t('Delete')}
                </button>
            )}
        </li>
    );
}
