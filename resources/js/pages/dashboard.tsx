import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Avatar } from '@/components/avatar';
import { Card } from '@/components/card';
import { CommunityImage } from '@/components/community-image';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { CommunitySummary } from './community/types';
import type { DiarySummary } from './diary/types';
import type { TimelinePostEntry } from './timeline/types';

interface DashboardProps extends PageProps {
    timeline: TimelinePostEntry[];
    diaries: DiarySummary[];
    communities: CommunitySummary[];
}

function Section({ title, href, empty, children }: { title: string; href: string; empty: boolean; children: ReactNode }) {
    const t = useT();
    if (empty) {
        return null;
    }
    return (
        <Card>
            <div className="flex items-center border-b border-slate-100 px-5 py-3 dark:border-slate-700">
                <h2 className="flex-1 text-sm font-semibold">{title}</h2>
                <Link href={href} className="text-xs text-blue-600 hover:underline dark:text-blue-400">
                    {t('View all')}
                </Link>
            </div>
            <ul className="divide-y divide-slate-100 dark:divide-slate-700">{children}</ul>
        </Card>
    );
}

export default function Dashboard() {
    const t = useT();
    const { auth, timeline, diaries, communities } = usePage<DashboardProps>().props;
    const user = auth.user;

    if (!user) {
        return null;
    }

    const nothing = timeline.length === 0 && diaries.length === 0 && communities.length === 0;

    return (
        <>
            <Head title={t('Home')} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <Card className="px-5 py-4">
                    <h1 className="text-xl font-semibold">{t('Hello, :name', { name: user.name })}</h1>
                </Card>

                {nothing && (
                    <Card className="px-5 py-4">
                        <p className="text-sm text-slate-600 dark:text-slate-300">{t('Nothing to show yet.')}</p>
                    </Card>
                )}

                <Section title={t('Timeline')} href="/m/timeline" empty={timeline.length === 0}>
                    {timeline.map((post) => (
                        <li key={post.id}>
                            <Link
                                href={`/m/timeline/${post.id}`}
                                className="flex items-start gap-3 px-5 py-3 hover:bg-slate-50 dark:hover:bg-slate-800"
                            >
                                <Avatar id={post.author.id} name={post.author.name} src={null} size="sm" />
                                <div className="min-w-0 flex-1">
                                    <p className="line-clamp-2 text-sm">{post.body}</p>
                                    <p className="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400">
                                        {post.author.name} &mdash; {new Date(post.createdAt).toLocaleDateString()}
                                    </p>
                                </div>
                            </Link>
                        </li>
                    ))}
                </Section>

                <Section title={t('Latest diaries')} href="/m/diary/list" empty={diaries.length === 0}>
                    {diaries.map((diary) => (
                        <li key={diary.id}>
                            <Link
                                href={`/m/diary/${diary.id}`}
                                className="flex items-start gap-3 px-5 py-3 hover:bg-slate-50 dark:hover:bg-slate-800"
                            >
                                <Avatar id={diary.author.id} name={diary.author.name} src={null} size="sm" />
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">{diary.title}</p>
                                    <p className="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400">
                                        {diary.author.name} &mdash; {new Date(diary.createdAt).toLocaleDateString()}
                                    </p>
                                </div>
                            </Link>
                        </li>
                    ))}
                </Section>

                <Section title={t('My %communities%')} href="/m/community/joined" empty={communities.length === 0}>
                    {communities.map((community) => (
                        <li key={community.id}>
                            <Link
                                href={`/m/community/${community.id}`}
                                className="flex items-center gap-3 px-5 py-3 hover:bg-slate-50 dark:hover:bg-slate-800"
                            >
                                <CommunityImage id={community.id} name={community.name} src={community.imageUrl} className="size-10" textClassName="text-base" />
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">{community.name}</p>
                                    <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                        {t(':count members', { count: community.memberCount })}
                                    </p>
                                </div>
                            </Link>
                        </li>
                    ))}
                </Section>
            </main>
        </>
    );
}
