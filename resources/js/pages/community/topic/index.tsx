import { Head, Link, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Avatar } from '@/components/avatar';
import { EntryRow } from '@/components/entry-row';
import { PageHeading } from '@/components/page-heading';
import { Pagination } from '@/components/pagination';
import { ActionLink } from '@/components/ui/action-link';
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
    const { community, topics, canPost } = usePage<IndexProps>().props;

    return (
        <>
            <Head title={t('%Topics%')} />
            <PageHeading
                title={
                    <>
                        <Link href={`/m/community/${community.id}`} className="hover:underline">
                            {community.name}
                        </Link>
                        {' — '}
                        {t('%Topics%')}
                    </>
                }
                action={
                    canPost && (
                        <ActionLink href={`/m/community/${community.id}/topic/new`}>
                            <Plus className="size-4" strokeWidth={2.25} aria-hidden />
                            {t('Create a %topic%')}
                        </ActionLink>
                    )
                }
            />

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
        </>
    );
}
