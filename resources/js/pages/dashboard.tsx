import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Avatar } from '@/components/avatar';
import { List, ListRow, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { DiarySummary } from './diary/types';
import type { TimelinePostEntry } from './timeline/types';

interface CommunityActivityEntry {
    kind: 'topic' | 'event';
    id: number;
    name: string;
    commentCount: number;
    community: { id: number; name: string };
    updatedAt: string;
}

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

/** Attention notices — pending friend requests, unread messages, and join requests awaiting the
 *  viewer's approval. Rendered only when something needs attention. */
function AnnouncementsPanel({ announcements }: { announcements: Announcements }) {
    const t = useT();
    const { friendRequests, unreadMessages, communityApprovals } = announcements;

    if (friendRequests === 0 && unreadMessages === 0 && communityApprovals.length === 0) {
        return null;
    }

    return (
        <Panel flush title={t('Notices')}>
            <List>
                {friendRequests > 0 && (
                    <ListRow href="/m/friend/manage" chevron>
                        <span className="min-w-0 flex-1 text-sm text-foreground">
                            {t(':count pending %friend% requests', { count: friendRequests })}
                        </span>
                    </ListRow>
                )}
                {unreadMessages > 0 && (
                    <ListRow href="/m/message" chevron>
                        <span className="min-w-0 flex-1 text-sm text-foreground">
                            {t(':count unread messages', { count: unreadMessages })}
                        </span>
                    </ListRow>
                )}
                {communityApprovals.map((approval) => (
                    <ListRow key={approval.communityId} href={`/m/community/${approval.communityId}/pending`} chevron>
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

function DiaryRow({ diary, showAuthor }: { diary: DiarySummary; showAuthor: boolean }) {
    const t = useT();
    return (
        <ListRow href={`/m/diary/${diary.id}`} chevron>
            {showAuthor && <Avatar id={diary.author.id} name={diary.author.name} src={diary.author.imageUrl} size="sm" decorative />}
            <div className="min-w-0 flex-1">
                <p className="truncate font-medium text-foreground">
                    {diary.title}
                    {diary.hasImages && (
                        <span title={t('This entry has photos')} aria-label={t('This entry has photos')}>
                            {' '}
                            📷
                        </span>
                    )}
                </p>
                <p className="mt-0.5 truncate text-xs text-muted-foreground">
                    {showAuthor ? `${diary.author.name} — ` : ''}
                    {new Date(diary.createdAt).toLocaleDateString()}
                </p>
            </div>
        </ListRow>
    );
}

function TimelineRow({ post }: { post: TimelinePostEntry }) {
    return (
        <ListRow href={`/m/timeline/${post.id}`} chevron className="items-start">
            <Avatar id={post.author.id} name={post.author.name} src={post.author.imageUrl} size="sm" decorative />
            <div className="min-w-0 flex-1">
                <p className="line-clamp-2 text-sm text-foreground">{post.body}</p>
                <p className="mt-0.5 truncate text-xs text-muted-foreground">
                    {post.author.name} &mdash; {new Date(post.createdAt).toLocaleDateString()}
                </p>
            </div>
        </ListRow>
    );
}

function ActivityRow({ entry }: { entry: CommunityActivityEntry }) {
    const t = useT();
    const href = entry.kind === 'topic' ? `/m/community/topic/${entry.id}` : `/m/community/event/${entry.id}`;
    return (
        <ListRow href={href} chevron>
            <span className="shrink-0 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                {entry.kind === 'topic' ? t('%Topic%') : t('Event')}
            </span>
            <div className="min-w-0 flex-1">
                <p className="truncate font-medium text-foreground">{entry.name}</p>
                <p className="mt-0.5 truncate text-xs text-muted-foreground">
                    {entry.community.name}
                    {entry.commentCount > 0 && ` · ${t(':count comments', { count: entry.commentCount })}`}
                </p>
            </div>
        </ListRow>
    );
}

export default function Dashboard() {
    const t = useT();
    const { auth, announcements, diaries, timeline, communityActivity, myDiaries } = usePage<DashboardProps>().props;
    const user = auth.user;

    if (!user) {
        return null;
    }

    const everythingEmpty =
        diaries.length === 0 && timeline.length === 0 && communityActivity.length === 0 && myDiaries.length === 0;

    return (
        <>
            <Head title={t('Home')} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="sr-only">{t('Home')}</h1>

                <AnnouncementsPanel announcements={announcements} />

                {everythingEmpty ? (
                    <Panel title={t('Welcome, :name.', { name: user.name })}>
                        <p className="text-sm text-muted-foreground">{t('Find people and places to fill your home.')}</p>
                        <div className="mt-4">
                            <List>
                                <ListRow href="/m/member/search" chevron>
                                    <span className="min-w-0 flex-1 text-sm text-foreground">{t('Search members')}</span>
                                </ListRow>
                                <ListRow href="/m/community/search" chevron>
                                    <span className="min-w-0 flex-1 text-sm text-foreground">{t('Search %communities%')}</span>
                                </ListRow>
                                <ListRow href="/m/diary/new" chevron>
                                    <span className="min-w-0 flex-1 text-sm text-foreground">{t('Post %diary%')}</span>
                                </ListRow>
                            </List>
                        </div>
                    </Panel>
                ) : (
                    <>
                        {diaries.length > 0 && (
                            <DigestSection
                                title={t('Latest diaries')}
                                viewAllHref="/m/diary/list"
                                extra={
                                    <Link href="/m/diary/listFriend" className="text-link hover:underline">
                                        {t('%Diaries% of %My_friends%')}
                                    </Link>
                                }
                            >
                                {diaries.map((diary) => (
                                    <DiaryRow key={diary.id} diary={diary} showAuthor />
                                ))}
                            </DigestSection>
                        )}

                        {timeline.length > 0 && (
                            <DigestSection title={t('Timeline')} viewAllHref="/m/timeline">
                                {timeline.map((post) => (
                                    <TimelineRow key={post.id} post={post} />
                                ))}
                            </DigestSection>
                        )}

                        {communityActivity.length > 0 && (
                            <DigestSection title={t('Recent %community% activity')} viewAllHref="/m/community/recent">
                                {communityActivity.map((entry) => (
                                    <ActivityRow key={`${entry.kind}-${entry.id}`} entry={entry} />
                                ))}
                            </DigestSection>
                        )}

                        {myDiaries.length > 0 && (
                            <DigestSection title={t('My recent %diaries%')} viewAllHref={`/m/diary/listMember/${user.id}`}>
                                {myDiaries.map((diary) => (
                                    <DiaryRow key={diary.id} diary={diary} showAuthor={false} />
                                ))}
                            </DigestSection>
                        )}
                    </>
                )}
            </main>
        </>
    );
}
