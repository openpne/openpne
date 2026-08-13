import { Head, Link, usePage } from '@inertiajs/react';
import { BellOff } from 'lucide-react';
import { CommunityImage } from '@/components/community-image';
import { CountPill } from '@/components/count-pill';
import { Pagination } from '@/components/pagination';
import { Timestamp } from '@/components/timestamp';
import { List, ListRow, Panel, stretchedLink } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { MemberRef, PaginatedCommunities, PaginatedTalkRooms, TalkRoomRow } from './types';

/** Per-group talk state for the viewer, keyed by group id. Empty on someone else's list. */
type TalkUnread = Record<string, { count: number; muted: boolean } | undefined>;

/**
 * Two screens behind one route. `rooms` is the viewer's own conversations, ordered by what was last
 * said; `grid` is the plain membership grid another member's list (or a site with talk off) gets.
 * The server picks, and says so — the client never infers the shape from which props arrived.
 */
type ListProps = PageProps & { owner: MemberRef; isOwner: boolean } & (
        | { view: 'rooms'; rooms: PaginatedTalkRooms }
        | { view: 'grid'; groups: PaginatedCommunities; talkUnread: TalkUnread }
    );

export default function CommunityList() {
    const t = useT();
    const props = usePage<ListProps>().props;
    const { owner, isOwner } = props;
    const title = isOwner ? t('My %communities%') : t(":name's %communities%", { name: owner.name });
    const isEmpty = props.view === 'rooms' ? props.rooms.data.length === 0 : props.groups.data.length === 0;

    return (
        <>
            <Head title={title} />
            {isEmpty ? (
                <Panel>
                    <p className="text-sm text-muted-foreground">{t('No %communities% to show.')}</p>
                </Panel>
            ) : props.view === 'rooms' ? (
                <TalkRooms rooms={props.rooms} />
            ) : (
                <CommunityGrid groups={props.groups} talkUnread={props.talkUnread} />
            )}
        </>
    );
}

/**
 * The viewer's conversations. A row is one link and it goes to the talk, not to the group top: the
 * group's own page is a click away from there, and a list ordered by what was last said is asking
 * "where is the conversation", not "what is this group".
 */
function TalkRooms({ rooms }: { rooms: PaginatedTalkRooms }) {
    return (
        <>
            <Panel flush>
                <List>
                    {rooms.data.map((room) => (
                        <RoomRow key={room.id} room={room} />
                    ))}
                </List>
            </Panel>
            <Pagination meta={rooms.meta} />
        </>
    );
}

function RoomRow({ room }: { room: TalkRoomRow }) {
    const t = useT();
    const latest = room.latest;

    return (
        <ListRow rowLink className="items-start">
            <CommunityImage name={room.name} src={room.imageUrl} className="size-12" textClassName="text-base" decorative />
            <div className="min-w-0 flex-1">
                <div className="flex min-w-0 items-center gap-1.5">
                    {/* The link is named by the group alone: the preview beside it is the room's newest
                        line, which changes under the reader and never names where the row goes. */}
                    <span className="truncate text-base text-foreground">
                        <Link href={`/groups/${room.id}/talk`} className={stretchedLink}>
                            {room.name}
                        </Link>
                    </span>
                    {room.muted && <BellOff className="size-3.5 shrink-0 text-muted-foreground" aria-label={t('Muted')} />}
                </div>
                <p className="mt-0.5 truncate text-sm text-muted-foreground">
                    {latest === null ? (
                        t('No messages yet.')
                    ) : (
                        <>
                            {latest.authorName ?? t('Withdrawn member')}
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
                <CountPill
                    count={room.unread}
                    label={t(':count unread messages', { count: room.unread })}
                    className={cn(room.muted && 'bg-muted text-muted-foreground')}
                />
            </div>
        </ListRow>
    );
}

/** Another member's memberships, or any list on a site with talk switched off: a plain group grid. */
function CommunityGrid({ groups, talkUnread }: { groups: PaginatedCommunities; talkUnread: TalkUnread }) {
    const t = useT();

    return (
        <>
            <Panel>
                <ul className="grid grid-cols-3 gap-4 sm:grid-cols-4">
                    {groups.data.map((group) => {
                        const talk = talkUnread[String(group.id)];
                        const unread = talk?.count ?? 0;

                        return (
                            <li key={group.id}>
                                <Link href={`/groups/${group.id}`} className="flex flex-col gap-1">
                                    <span className="relative block">
                                        <CommunityImage name={group.name} src={group.imageUrl} className="aspect-square w-full" textClassName="text-2xl" decorative />
                                        {/* The whole tile is one link, so the pill can name the count itself
                                            rather than needing a separate sr-only label. A muted group keeps
                                            its number but gives up the pill's insistence — it is still there
                                            to be found, it just stops asking to be looked at. */}
                                        <CountPill
                                            count={unread}
                                            label={t(':count unread messages')}
                                            className={cn(
                                                'absolute -top-1.5 -right-1.5',
                                                talk?.muted === true && 'bg-muted text-muted-foreground',
                                            )}
                                        />
                                    </span>
                                    <span className="flex min-w-0 items-center gap-1">
                                        <span className="truncate text-sm">{group.name}</span>
                                        {talk?.muted === true && (
                                            <BellOff className="size-3 shrink-0 text-muted-foreground" aria-label={t('Muted')} />
                                        )}
                                    </span>
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </Panel>
            <Pagination meta={groups.meta} />
        </>
    );
}
