import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { List, ListRow, Panel, stretchedLink } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { ActivityRow, type CommunityActivityEntry } from './community/activity-row';
import { DiaryRow } from './diary/diary-row';
import type { DiarySummary } from './diary/types';
import { WelcomePanel } from './home/welcome';
import { TimelineRow } from './timeline/timeline-row';
import type { TimelinePostEntry } from './timeline/types';

interface Announcements {
    friendRequests: number;
    unreadMessages: number;
    groupApprovals: { groupId: number; groupName: string; count: number }[];
    adminTransfers: { groupId: number; groupName: string }[];
}

interface DashboardProps extends PageProps {
    announcements: Announcements;
    newcomer: boolean;
    friendDiaries: DiarySummary[];
    diaries: DiarySummary[];
    timeline: TimelinePostEntry[];
    groupActivity: CommunityActivityEntry[];
    myDiaries: DiarySummary[];
}

/**
 * "Needs action" counts only: the unread-notification count stays with the bell
 * (docs/internals/notifications.md, "The three layers").
 */
function AnnouncementsPanel({ announcements }: { announcements: Announcements }) {
    const t = useT();
    const features = usePage<PageProps>().props.enabledFeatures;
    // Each notice links into the unit it belongs to (the server already reports it as nothing).
    const friendRequests = features.friend ? announcements.friendRequests : 0;
    const unreadMessages = features.directMessage ? announcements.unreadMessages : 0;
    const groupApprovals = features.group ? announcements.groupApprovals : [];
    const adminTransfers = features.group ? announcements.adminTransfers : [];

    if (friendRequests === 0 && unreadMessages === 0 && groupApprovals.length === 0 && adminTransfers.length === 0) {
        return null;
    }

    return (
        <Panel flush title={t('Notices')}>
            <List>
                {adminTransfers.map((transfer) => (
                    <ListRow key={transfer.groupId} rowLink chevron>
                        <span className="min-w-0 flex-1 text-sm text-foreground">
                            <Link href={`/groups/${transfer.groupId}`} className={stretchedLink}>
                                {t('The administrator of :name asks you to take over the administration.', { name: transfer.groupName })}
                            </Link>
                        </span>
                    </ListRow>
                ))}
                {groupApprovals.map((approval) => (
                    <ListRow key={approval.groupId} rowLink chevron>
                        <span className="min-w-0 flex-1 text-sm text-foreground">
                            <Link href={`/groups/${approval.groupId}/members/pending`} className={stretchedLink}>
                                {approval.count === 1
                                    ? t('1 join request for :community', { community: approval.groupName })
                                    : t(':count join requests for :community', { count: approval.count, community: approval.groupName })}
                            </Link>
                        </span>
                    </ListRow>
                ))}
                {friendRequests > 0 && (
                    <ListRow rowLink chevron>
                        <span className="min-w-0 flex-1 text-sm text-foreground">
                            <Link href="/friend/requests" className={stretchedLink}>
                                {friendRequests === 1 ? t('1 pending %friend% request') : t(':count pending %friend% requests', { count: friendRequests })}
                            </Link>
                        </span>
                    </ListRow>
                )}
                {unreadMessages > 0 && (
                    <ListRow rowLink chevron>
                        <span className="min-w-0 flex-1 text-sm text-foreground">
                            <Link href="/messages" className={stretchedLink}>
                                {unreadMessages === 1
                                    ? t('1 conversation with new messages')
                                    : t(':count conversations with new messages', { count: unreadMessages })}
                            </Link>
                        </span>
                    </ListRow>
                )}
            </List>
        </Panel>
    );
}

function DigestSection({ title, viewAllHref, children }: { title: string; viewAllHref: string; children: ReactNode }) {
    const t = useT();
    return (
        <Panel
            flush
            title={title}
            right={
                <Link href={viewAllHref} className="shrink-0 text-xs text-link hover:underline">
                    {t('View all')}
                </Link>
            }
        >
            <List>{children}</List>
        </Panel>
    );
}

export default function Dashboard() {
    const t = useT();
    const { auth, announcements, newcomer, friendDiaries, diaries, timeline, groupActivity, myDiaries, enabledFeatures } = usePage<DashboardProps>().props;
    const user = auth.user;

    if (!user) {
        return null;
    }

    return (
        <>
            <Head title={t("What's new")} />

            <AnnouncementsPanel announcements={announcements} />

            {newcomer && <WelcomePanel name={user.name} enabledFeatures={enabledFeatures} />}

            {/* A section's rows arrive empty once its unit is switched off; the check keeps the
                header and its deep links from outliving them. */}
            {enabledFeatures.diary && enabledFeatures.friend && friendDiaries.length > 0 && (
                <DigestSection title={t('%Diaries% of %My_friends%')} viewAllHref="/diary/listFriend">
                    {friendDiaries.map((diary) => (
                        <DiaryRow key={diary.id} diary={diary} />
                    ))}
                </DigestSection>
            )}

            {enabledFeatures.diary && diaries.length > 0 && (
                <DigestSection title={t('Latest %diaries%')} viewAllHref="/diary/list">
                    {diaries.map((diary) => (
                        <DiaryRow key={diary.id} diary={diary} />
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

            {enabledFeatures.group && groupActivity.length > 0 && (
                <DigestSection title={t('Recent %community% activity')} viewAllHref="/groups/recent">
                    {groupActivity.map((entry) => (
                        <ActivityRow key={`${entry.kind}-${entry.id}`} entry={entry} />
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
        </>
    );
}
