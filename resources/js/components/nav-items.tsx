import { Link, usePage } from '@inertiajs/react';
import { BellOff } from 'lucide-react';
import { CommunityImage } from '@/components/community-image';
import { CountPill } from '@/components/count-pill';
import { TALK_ROOMS_HREF, visibleNavSections } from '@/lib/member-chrome';
import { badgePhrase, unreadMessagesPhrase } from '@/lib/count-phrase';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps, TalkNavRooms } from '@/types';

/**
 * Rendered from the member chrome registry, the same source the page frame reads for hub headers, so
 * nav labels and hub headings cannot drift. `rooms` is a parameter rather than a prop read here
 * because only the desktop sidebar wants it.
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
                                // Hover paints the background and leaves the text muted: in dark
                                // --accent-foreground and --foreground are the same value, so
                                // brightening it would make a hovered item identical to the current one.
                                (active
                                    ? 'bg-accent text-foreground'
                                    : 'text-muted-foreground hover:bg-accent')
                            }
                        >
                            <Icon className="size-5 shrink-0" strokeWidth={active ? 2.25 : 2} />
                            <span className="flex-1 truncate">{t(label.key, label.replacements)}</span>
                            {badge && <CountPill count={count} label={badgePhrase(t, badge, count)} />}
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
                            {/* The row is one link, so the pill's phrase joins its name; a muted room
                                keeps the number and gives up the pill's insistence. */}
                            <CountPill
                                count={room.unread}
                                label={unreadMessagesPhrase(t, room.unread)}
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
