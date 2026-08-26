import { Link, usePage } from '@inertiajs/react';
import { BellOff } from 'lucide-react';
import { CommunityImage } from '@/components/community-image';
import { CountPill } from '@/components/count-pill';
import { TALK_ROOMS_HREF, visibleNavSections } from '@/lib/member-chrome';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps, TalkNavRooms } from '@/types';

/**
 * Shared nav list for LeftNav (desktop) and NavDrawer (mobile), rendered from the member chrome
 * registry — the same source the page frame reads for hub headers, so nav labels and hub h1s cannot
 * drift. Home is the brand row, so it is omitted. Friends and Messages carry an attention badge
 * (pending requests / unread) from the shared `unread` counts. Sections of a switched-off unit are
 * dropped (the Classic navigation does the same from its own route table).
 *
 * `rooms` opens the groups entry into a room list. It is a parameter rather than a prop read here
 * because only the desktop sidebar wants it: on a phone the bottom bar is the way into a group, and
 * a drawer that had to be opened first is not where a conversation list belongs.
 */
export function NavItems({ onNavigate, rooms }: { onNavigate?: () => void; rooms?: TalkNavRooms | null }) {
    const t = useT();
    const { url, props } = usePage<PageProps>();
    const unread = props.unread;

    return (
        <ul className="flex flex-col gap-1">
            {visibleNavSections(props.enabledFeatures).map(({ href, icon: Icon, label, match, badge }) => {
                const active = match.some((prefix) => url.startsWith(prefix));
                const count = badge ? (unread?.[badge.count] ?? 0) : 0;
                return (
                    <li key={href}>
                        <Link
                            href={href}
                            onClick={onNavigate}
                            aria-current={active ? 'page' : undefined}
                            className={
                                'flex min-h-11 items-center gap-3 rounded-full px-3 text-base transition ' +
                                // Hover paints the background and leaves the text muted, so the two
                                // never converge: the current item is the only one showing the
                                // background *and* foreground text. Brightening the text on hover
                                // would make a hovered item identical to the current one — in dark
                                // --accent-foreground and --foreground are the same value — which is
                                // what font weight used to hide before it was removed from the
                                // non-unread states.
                                (active
                                    ? 'bg-accent text-foreground'
                                    : 'text-muted-foreground hover:bg-accent')
                            }
                        >
                            <Icon className="size-5 shrink-0" strokeWidth={active ? 2.25 : 2} />
                            <span className="flex-1 truncate">{t(label.key, label.replacements)}</span>
                            {badge && <CountPill count={count} label={t(badge.label.key, { count })} />}
                        </Link>
                        {href === TALK_ROOMS_HREF && rooms && rooms.rooms.length > 0 && (
                            <TalkRooms rooms={rooms} url={url} />
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

/** A room row and the "view all" that follows the last one: no horizontal padding of their own, so
 *  each sets the indent that lines its text up with the room names. */
const ROOM_ROW = 'flex min-h-9 items-center gap-2 rounded-full pr-2 text-sm transition';

/**
 * The conversations under the groups entry, newest-spoken-in first — the joined list's own order, so
 * the sidebar and the list it links to never disagree about where a room sits. A row opens the talk
 * rather than the group, for the reason the list's rows do: an order like this is answering "where is
 * the conversation".
 */
function TalkRooms({ rooms: { rooms, hasMore }, url }: { rooms: TalkNavRooms; url: string }) {
    const t = useT();

    return (
        <ul className="mt-0.5 mb-1 ml-3 flex flex-col gap-0.5">
            {rooms.map((room) => {
                const href = `/groups/${room.id}/talk`;
                const active = url.startsWith(href);

                return (
                    <li key={room.id}>
                        <Link
                            href={href}
                            aria-current={active ? 'page' : undefined}
                            className={cn(ROOM_ROW, 'pl-2', active ? 'bg-accent text-foreground' : 'text-muted-foreground hover:bg-accent')}
                        >
                            <CommunityImage name={room.name} src={room.imageUrl} className="size-6" textClassName="text-[10px]" decorative />
                            <span className="min-w-0 flex-1 truncate">{room.name}</span>
                            {room.muted && <BellOff className="size-3 shrink-0" aria-label={t('Muted')} />}
                            {/* The row is one link, so the pill's phrase joins its name
                                (components/count-pill.tsx). A muted room keeps that number and gives
                                up the pill's insistence, as its row on the joined list does. */}
                            <CountPill
                                count={room.unread}
                                label={t(':count unread messages', { count: room.unread })}
                                className={cn(room.muted && 'bg-muted text-muted-foreground')}
                            />
                        </Link>
                    </li>
                );
            })}
            {hasMore && (
                <li>
                    <Link href={TALK_ROOMS_HREF} className={cn(ROOM_ROW, 'pl-10 text-muted-foreground hover:bg-accent')}>
                        {t('View all')}
                    </Link>
                </li>
            )}
        </ul>
    );
}
