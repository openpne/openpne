import { Head, usePage } from '@inertiajs/react';
import { Pagination, type PaginationMeta } from '@/components/pagination';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { ActivityRow, type CommunityActivityEntry } from '@/pages/community/activity-row';

interface RecentProps extends PageProps {
    topics: { data: CommunityActivityEntry[]; meta: PaginationMeta };
}

export default function GroupTopicRecent() {
    const t = useT();
    const { topics } = usePage<RecentProps>().props;

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
                            {topics.data.map((entry) => (
                                <ActivityRow key={entry.id} entry={entry} />
                            ))}
                        </List>
                    </Panel>
                    <Pagination meta={topics.meta} />
                </>
            )}
        </>
    );
}
