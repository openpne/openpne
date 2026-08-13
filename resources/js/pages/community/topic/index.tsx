import { Head, usePage } from '@inertiajs/react';
import { EntryRow } from '@/components/entry-row';
import { Pagination } from '@/components/pagination';
import { Timestamp } from '@/components/timestamp';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { CommunitySummary, PaginatedTopics } from '../types';

interface IndexProps extends PageProps {
    group: CommunitySummary;
    topics: PaginatedTopics;
    canPost: boolean;
}

export default function CommunityTopicIndex() {
    const t = useT();
    const { topics } = usePage<IndexProps>().props;

    return (
        <>
            <Head title={t('%Topics%')} />
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
                                    href={`/topics/${topic.id}`}
                                    author={topic.author}
                                    content={topic.name}
                                    date={<Timestamp at={topic.updatedAt} preset="listStamp" />}
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
