import { Head, Link, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { Pagination } from '@/components/pagination';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { CommunitySummary, PaginatedTopics } from '../types';

interface IndexProps extends PageProps {
    community: CommunitySummary;
    topics: PaginatedTopics;
    canPost: boolean;
}

export default function CommunityTopicIndex() {
    const t = useT();
    const { community, topics, canPost, flash } = usePage<IndexProps>().props;

    return (
        <>
            <Head title={t('%Topics%')} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                {flash.status && <p role="status">{flash.status}</p>}

                <div className="flex items-center justify-between gap-3">
                    <h1 className="min-w-0 text-2xl font-semibold">
                        <Link href={`/m/community/${community.id}`} className="hover:underline">
                            {community.name}
                        </Link>
                        {' — '}
                        {t('%Topics%')}
                    </h1>
                    {canPost && (
                        <Link href={`/m/community/${community.id}/topic/new`} className="shrink-0 text-sm hover:underline">
                            {t('Post a new %topic%')}
                        </Link>
                    )}
                </div>

                {topics.data.length === 0 ? (
                    <p>{t('No %topics% to show.')}</p>
                ) : (
                    <>
                        <ul className="divide-y">
                            {topics.data.map((topic) => (
                                <li key={topic.id}>
                                    <Link
                                        href={`/m/community/topic/${topic.id}`}
                                        className="flex items-start gap-3 py-3 hover:bg-muted/40"
                                    >
                                        <Avatar id={topic.author?.id ?? 0} name={topic.author?.name ?? ''} src={topic.author?.imageUrl ?? null} size="sm" />
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate font-medium">
                                                {topic.name} ({topic.commentCount})
                                            </p>
                                            <p className="truncate text-xs text-muted-foreground">
                                                {topic.author?.name ?? t('Withdrawn member')} &mdash; {new Date(topic.updatedAt).toLocaleString()}
                                            </p>
                                        </div>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                        <Pagination meta={topics.meta} />
                    </>
                )}
            </main>
        </>
    );
}
