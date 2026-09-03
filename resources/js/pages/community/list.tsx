import { Head, Link, usePage } from '@inertiajs/react';
import { CommunityImage } from '@/components/community-image';
import { Pagination } from '@/components/pagination';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { RoomRow } from './room-row';
import type { MemberRef, PaginatedCommunities, PaginatedTalkRooms } from './types';

/**
 * Two screens behind one route. `rooms` is the viewer's own conversations, ordered by what was last
 * said; `grid` is the plain membership grid another member's list (or a site with talk off) gets.
 * The server picks, and says so — the client never infers the shape from which props arrived.
 */
type ListProps = PageProps & { owner: MemberRef; isOwner: boolean } & (
        | { view: 'rooms'; rooms: PaginatedTalkRooms }
        | { view: 'grid'; groups: PaginatedCommunities }
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
                <CommunityGrid groups={props.groups} />
            )}
        </>
    );
}

/** The viewer's conversations, whole: the same rows the dashboard digest leads with, with a pager. */
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

/** Another member's memberships, or any list on a site with talk switched off: a plain group grid. */
function CommunityGrid({ groups }: { groups: PaginatedCommunities }) {
    return (
        <>
            <Panel>
                <ul className="grid grid-cols-3 gap-4 sm:grid-cols-4">
                    {groups.data.map((group) => (
                        <li key={group.id}>
                            <Link href={`/groups/${group.id}`} className="flex flex-col gap-1">
                                <CommunityImage name={group.name} src={group.imageUrl} className="aspect-square w-full" textClassName="text-2xl" decorative />
                                <span className="truncate text-sm">{group.name}</span>
                            </Link>
                        </li>
                    ))}
                </ul>
            </Panel>
            <Pagination meta={groups.meta} />
        </>
    );
}
