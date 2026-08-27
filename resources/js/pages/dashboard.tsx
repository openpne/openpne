import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { List, ListRow, Panel, stretchedLink } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { ActivityRow, type CommunityActivityEntry } from './community/activity-row';
import { RoomRow } from './community/room-row';
import type { TalkRoomRow } from './community/types';
import { DiaryRow } from './diary/diary-row';
import type { DiarySummary } from './diary/types';
import { WelcomePanel } from './home/welcome';
import { TimelineRow } from './timeline/timeline-row';
import type { TimelinePostEntry } from './timeline/types';

interface Announcements {
    friendRequests: number;
    unreadMessages: number;
    groupApprovals: { groupId: number; groupName: string; count: number }[];
}

interface DashboardProps extends PageProps {
    announcements: Announcements;
    talkRooms: TalkRoomRow[];
    diaries: DiarySummary[];
    timeline: TimelinePostEntry[];
    groupActivity: CommunityActivityEntry[];
    myDiaries: DiarySummary[];
}

/**
 * Attention notices — pending friend requests, conversations with something new, and join requests
 * awaiting the viewer's approval. Layer-1 "needs action" counts only: the layer-3 unread-notification count is
 * the nav bell / bottom bar's job — a row restating that badge would say nothing new while
 * occupying the top of the dashboard.
 */
function AnnouncementsPanel({ announcements }: { announcements: Announcements }) {
    const t = useT();
    const features = usePage<PageProps>().props.enabledFeatures;
    // Each notice links into the unit it belongs to (the server already reports it as nothing).
    const friendRequests = features.friend ? announcements.friendRequests : 0;
    const unreadMessages = features.directMessage ? announcements.unreadMessages : 0;
    const groupApprovals = features.group ? announcements.groupApprovals : [];

    if (friendRequests === 0 && unreadMessages === 0 && groupApprovals.length === 0) {
        return null;
    }

    return (
        <Panel flush title={t('Notices')}>
            <List>
                {friendRequests > 0 && (
                    <ListRow rowLink chevron>
                        <span className="min-w-0 flex-1 text-sm text-foreground">
                            <Link href="/friend/requests" className={stretchedLink}>
                                {t(':count pending %friend% requests', { count: friendRequests })}
                            </Link>
                        </span>
                    </ListRow>
                )}
                {unreadMessages > 0 && (
                    <ListRow rowLink chevron>
                        <span className="min-w-0 flex-1 text-sm text-foreground">
                            <Link href="/messages" className={stretchedLink}>
                                {t(':count conversations with new messages', { count: unreadMessages })}
                            </Link>
                        </span>
                    </ListRow>
                )}
                {groupApprovals.map((approval) => (
                    <ListRow key={approval.groupId} rowLink chevron>
                        <span className="min-w-0 flex-1 text-sm text-foreground">
                            <Link href={`/groups/${approval.groupId}/members/pending`} className={stretchedLink}>
                                {t(':count join requests for :community', { count: approval.count, community: approval.groupName })}
                            </Link>
                        </span>
                    </ListRow>
                ))}
            </List>
        </Panel>
    );
}

/** A section shown only when it has rows: title band with a "View all" (and optional extra) link. */
function DigestSection({ title, viewAllHref, extra, children }: { title: string; viewAllHref: string; extra?: ReactNode; children: ReactNode }) {
    const t = useT();
    return (
        <Panel
            flush
            title={title}
            right={
                <span className="flex shrink-0 items-center gap-3 text-xs">
                    {extra}
                    <Link href={viewAllHref} className="text-link hover:underline">
                        {t('View all')}
                    </Link>
                </span>
            }
        >
            <List>{children}</List>
        </Panel>
    );
}

export default function Dashboard() {
    const t = useT();
    const { auth, announcements, talkRooms, diaries, timeline, groupActivity, myDiaries, enabledFeatures } = usePage<DashboardProps>().props;
    const user = auth.user;

    if (!user) {
        return null;
    }

    const everythingEmpty =
        diaries.length === 0 && timeline.length === 0 && groupActivity.length === 0 && myDiaries.length === 0;

    return (
        <>
            <Head title={t("What's new")} />

            <AnnouncementsPanel announcements={announcements} />

            {/* Talk leads the screen: the conversations are what a member comes back for; everything
                below them is reading. It sits outside the welcome branch and does not decide it —
                a room is a place to talk, not the feeds and people that panel offers to go find. */}
            {enabledFeatures.groupTalk && talkRooms.length > 0 && (
                <DigestSection title={t('Talk')} viewAllHref="/groups/mine">
                    {talkRooms.map((room) => (
                        <RoomRow key={room.id} room={room} />
                    ))}
                </DigestSection>
            )}

            {everythingEmpty ? (
                <WelcomePanel name={user.name} enabledFeatures={enabledFeatures} />
            ) : (
                <>
                    {/* A section's rows arrive empty once its unit is switched off; the check keeps
                        the header and its deep links from outliving them. */}
                    {enabledFeatures.diary && diaries.length > 0 && (
                        <DigestSection
                            title={t('Latest %diaries%')}
                            viewAllHref="/diary/list"
                            extra={
                                // The friend lens goes with its unit; the section it hangs off stays.
                                enabledFeatures.friend && (
                                    <Link href="/diary/listFriend" className="text-link hover:underline">
                                        {t('%Diaries% of %My_friends%')}
                                    </Link>
                                )
                            }
                        >
                            {diaries.map((diary) => (
                                <DiaryRow key={diary.id} diary={diary} />
                            ))}
                        </DigestSection>
                    )}

                    {enabledFeatures.timeline && timeline.length > 0 && (
                        <DigestSection title={t('%Activity%')} viewAllHref="/timeline">
                            {timeline.map((post) => (
                                <TimelineRow key={post.id} post={post} />
                            ))}
                        </DigestSection>
                    )}

                    {enabledFeatures.group && groupActivity.length > 0 && (
                        <DigestSection title={t('Recent %community% activity')} viewAllHref="/groups/recent">
                            {groupActivity.map((entry) => (
                                <ActivityRow key={`${entry.kind}-${entry.id}`} entry={entry} />
                            ))}
                        </DigestSection>
                    )}

                    {enabledFeatures.diary && myDiaries.length > 0 && (
                        <DigestSection title={t('My recent %diaries%')} viewAllHref={`/diary/listMember/${user.id}`}>
                            {myDiaries.map((diary) => (
                                <DiaryRow key={diary.id} diary={diary} />
                            ))}
                        </DigestSection>
                    )}
                </>
            )}
        </>
    );
}
