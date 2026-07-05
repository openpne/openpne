import { Head, Link, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { EntryRow } from '@/components/entry-row';
import { FlashMessage } from '@/components/flash-message';
import { Pagination } from '@/components/pagination';
import { List, Panel } from '@/components/ui/surface';
import { formatDate } from '@/lib/date';
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
                <div className="flex items-center justify-between gap-3">
                    <h1 className="min-w-0 break-words text-xl font-semibold text-foreground">
                        <Link href={`/m/community/${community.id}`} className="hover:underline">
                            {community.name}
                        </Link>
                        {' — '}
                        {t('%Topics%')}
                    </h1>
                    {canPost && (
                        <Link href={`/m/community/${community.id}/topic/new`} className="shrink-0 text-sm text-link hover:underline">
                            {t('Post a new %topic%')}
                        </Link>
                    )}
                </div>

                {flash.status && <FlashMessage>{flash.status}</FlashMessage>}

                {topics.data.length === 0 ? (
                    <Panel>
                        <p className="text-sm text-muted-foreground">{t('No %topics% to show.')}</p>
                    </Panel>
                ) : (
                    <>
                        <Panel flush>
                            <List>
                                {topics.data.map((topic) => (
                                    <EntryRow
                                        key={topic.id}
                                        href={`/m/community/topic/${topic.id}`}
                                        leading={<Avatar id={topic.author?.id ?? 0} name={topic.author?.name ?? ''} src={topic.author?.imageUrl ?? null} size="sm" decorative />}
                                        title={topic.name}
                                        meta={[topic.author?.name ?? t('Withdrawn member'), formatDate(topic.updatedAt)]}
                                        commentCount={topic.commentCount}
                                    />
                                ))}
                            </List>
                        </Panel>
                        <Pagination meta={topics.meta} />
                    </>
                )}
            </main>
        </>
    );
}
