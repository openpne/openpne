import { Link } from '@inertiajs/react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { CountPill } from '@/components/count-pill';
import { Timestamp } from '@/components/timestamp';
import { ListRow, stretchedLink } from '@/components/ui/surface';
import { unreadMessagesPhrase } from '@/lib/count-phrase';
import { useT } from '@/lib/i18n';
import type { ConversationRowData } from '../types';

/**
 * One conversation on the list, built as the room list's row is: the row is a single link into the
 * conversation, named by the person alone — the preview beside it changes under the reader and never
 * says where the row goes.
 */
export function ConversationRow({ conversation }: { conversation: ConversationRowData }) {
    const t = useT();
    const { counterpart, latest, unread } = conversation;
    // A departed member leaves no id to key a conversation by, so all of them share one address.
    const href = `/messages/${counterpart?.id ?? 'withdrawn'}`;
    const name = counterpart?.name ?? t('Withdrawn member');

    return (
        <ListRow rowLink className="items-start">
            <Avatar id={counterpart?.id ?? 0} name={name} src={counterpart?.imageUrl ?? null} color={counterpart?.avatarColor ?? null} isAi={counterpart?.isAi ?? false} decorative />
            <div className="min-w-0 flex-1">
                <div className="flex min-w-0 items-center gap-1.5">
                    <p className="min-w-0 truncate text-base text-foreground">
                        <Link href={href} className={stretchedLink}>
                            {name}
                            {/* The pill is in the other column, outside this link, so its number
                                would belong to nothing. The row is what the count is about, and this
                                is the row. */}
                            {unread > 0 && <span className="sr-only"> {unreadMessagesPhrase(t, unread)}</span>}
                        </Link>
                    </p>
                    <AiChip isAi={counterpart?.isAi ?? false} />
                </div>
                {/* No author prefix: the row's title already names the only other person here, so a
                    1:1 preview needs no attribution. */}
                <p className="mt-0.5 truncate text-sm text-muted-foreground">{latest.body}</p>
            </div>
            <div className="flex shrink-0 flex-col items-end gap-1">
                <Timestamp at={latest.createdAt} preset="relative" className="text-xs text-muted-foreground" />
                <CountPill count={unread} />
            </div>
        </ListRow>
    );
}
