import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { EntryRow } from '@/components/entry-row';
import { List, ListRow, Panel } from '@/components/ui/surface';
import { formatDate } from '@/lib/date';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { ActivityRow, type CommunityActivityEntry } from './community/activity-row';
import { DiaryRow } from './diary/diary-row';
import type { DiarySummary } from './diary/types';
import type { TimelinePostEntry } from './timeline/types';

interface Announcements {
    friendRequests: number;
    unreadMessages: number;
    communityApprovals: { communityId: number; communityName: string; count: number }[];
}

interface DashboardProps extends PageProps {
    announcements: Announcements;
    diaries: DiarySummary[];
    timeline: TimelinePostEntry[];
    communityActivity: CommunityActivityEntry[];
    myDiaries: DiarySummary[];
}

/**
 * Attention notices — pending friend requests, unread messages, and join requests awaiting the
 * viewer's approval. Layer-1 "needs action" counts only: the layer-3 unread-notification count is
 * the nav bell / bottom bar's job — a row restating that badge would say nothing new while
 * occupying the top of the dashboard.
 */
function AnnouncementsPanel({ announcements }: { announcements: Announcements }) {
    const t = useT();
    const features = usePage<PageProps>().props.enabledFeatures;
    // Each notice links into the unit it belongs to (the server already reports it as nothing).
    const friendRequests = features.friend ? announcements.friendRequests : 0;
    const unreadMessages = features.message ? announcements.unreadMessages : 0;
    const communityApprovals = features.community ? announcements.communityApprovals : [];

    if (friendRequests === 0 && unreadMessages === 0 && communityApprovals.length === 0) {
        return null;
    }

    return (
        <Panel flush title={t('Notices')}>
            <List>
                {friendRequests > 0 && (
                    <ListRow href="/friend/requests" chevron>
                        <span className="min-w-0 flex-1 text-sm text-foreground">
                            {t(':count pending %friend% requests', { count: friendRequests })}
                        </span>
                    </ListRow>
                )}
                {unreadMessages > 0 && (
                    <ListRow href="/message" chevron>
                        <span className="min-w-0 flex-1 text-sm text-foreground">
                            {t(':count unread messages', { count: unreadMessages })}
                        </span>
                    </ListRow>
                )}
                {communityApprovals.map((approval) => (
                    <ListRow key={approval.communityId} href={`/community/member/pending?id=${approval.communityId}`} chevron>
                        <span className="min-w-0 flex-1 text-sm text-foreground">
                            {t(':count join requests for :community', { count: approval.count, community: approval.communityName })}
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

function TimelineRow({ post }: { post: TimelinePostEntry }) {
    return (
        <EntryRow
            href={`/timeline/${post.id}`}
            author={post.author}
            title={post.body}
            titleClassName="line-clamp-2 text-sm text-foreground"
            date={formatDate(post.createdAt)}
            replyCount={post.replyCount}
        />
    );
}

export default function Dashboard() {
    const t = useT();
    const { auth, announcements, diaries, timeline, communityActivity, myDiaries, enabledFeatures } = usePage<DashboardProps>().props;
    const user = auth.user;

    if (!user) {
        return null;
    }

    const everythingEmpty =
        diaries.length === 0 && timeline.length === 0 && communityActivity.length === 0 && myDiaries.length === 0;

    return (
        <>
            <Head title={t('Home')} />
            <h1 className="sr-only">{t('Home')}</h1>

            <AnnouncementsPanel announcements={announcements} />

            {everythingEmpty ? (
                <Panel title={t('Welcome, :name.', { name: user.name })}>
                    <p className="text-sm text-muted-foreground">{t('Find people and places to fill your home.')}</p>
                    <div className="mt-4">
                        <List>
                            <ListRow href="/member/search" chevron>
                                <span className="min-w-0 flex-1 text-sm text-foreground">{t('Search members')}</span>
                            </ListRow>
                            {enabledFeatures.community && (
                                <ListRow href="/community/search" chevron>
                                    <span className="min-w-0 flex-1 text-sm text-foreground">{t('Search %communities%')}</span>
                                </ListRow>
                            )}
                            {enabledFeatures.diary && (
                                <ListRow href="/diary/new" chevron>
                                    <span className="min-w-0 flex-1 text-sm text-foreground">{t('Post %diary%')}</span>
                                </ListRow>
                            )}
                        </List>
                    </div>
                </Panel>
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

                    {enabledFeatures.community && communityActivity.length > 0 && (
                        <DigestSection title={t('Recent %community% activity')} viewAllHref="/community/recent">
                            {communityActivity.map((entry) => (
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
