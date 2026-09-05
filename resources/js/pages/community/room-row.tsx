import { Link } from '@inertiajs/react';
import { BellOff } from 'lucide-react';
import { CommunityImage } from '@/components/community-image';
import { CountPill } from '@/components/count-pill';
import { Timestamp } from '@/components/timestamp';
import { ListRow, stretchedLink } from '@/components/ui/surface';
import { unreadMessagesPhrase } from '@/lib/count-phrase';
import { markedName } from '@/lib/identity-mark';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { TalkRoomRow } from './types';

/** The row is a single link, and it goes to the talk rather than to the group top. */
export function RoomRow({ room }: { room: TalkRoomRow }) {
    const t = useT();
    const latest = room.latest;
    // The one place the AI marker is text rather than an AiChip: the preview is a single truncated
    // line, and a pill cannot live inside one.
    const speaker = latest === null ? '' : markedName(latest.authorName ?? t('Withdrawn member'), latest.authorIsAi, t);

    return (
        <ListRow rowLink className="items-start">
            <CommunityImage name={room.name} src={room.imageUrl} className="size-12" textClassName="text-base" decorative />
            <div className="min-w-0 flex-1">
                <div className="flex min-w-0 items-center gap-1.5">
                    {/* The link is named by the group and its count and nothing else: the preview
                        changes under the reader, and the pill that prints the count sits in the
                        other column, outside this link. */}
                    <span className="truncate text-base text-foreground">
                        <Link href={`/groups/${room.id}/talk`} className={stretchedLink}>
                            {room.name}
                            {room.unread > 0 && <span className="sr-only"> {unreadMessagesPhrase(t, room.unread)}</span>}
                        </Link>
                    </span>
                    {room.muted && <BellOff className="size-3.5 shrink-0 text-muted-foreground" aria-label={t('Muted')} />}
                </div>
                <p className="mt-0.5 truncate text-sm text-muted-foreground">
                    {latest === null ? (
                        t('No messages yet.')
                    ) : (
                        <>
                            {speaker}
                            {': '}
                            {latest.body}
                        </>
                    )}
                </p>
            </div>
            <div className="flex shrink-0 flex-col items-end gap-1">
                {latest !== null && <Timestamp at={latest.createdAt} preset="relative" className="text-xs text-muted-foreground" />}
                {/* A muted room keeps its number but gives up the pill's insistence — it is still
                    there to be found, it just stops asking to be looked at. */}
                <CountPill count={room.unread} className={cn(room.muted && 'bg-muted text-muted-foreground')} />
            </div>
        </ListRow>
    );
}
