import { Head, usePage } from '@inertiajs/react';
import { Pagination, type PaginationMeta } from '@/components/pagination';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { ActivityRow, type CommunityActivityEntry } from '@/pages/community/activity-row';

interface RecentProps extends PageProps {
    events: { data: CommunityActivityEntry[]; meta: PaginationMeta };
}

export default function GroupEventRecent() {
    const t = useT();
    const { events } = usePage<RecentProps>().props;

    return (
        <>
            <Head title={t('Events')} />
            {events.data.length === 0 ? (
                <Panel>
                    <p className="text-sm text-muted-foreground">{t('No events to show.')}</p>
                </Panel>
            ) : (
                <>
                    <Panel flush>
                        <List>
                            {events.data.map((entry) => (
                                <ActivityRow key={entry.id} entry={entry} />
                            ))}
                        </List>
                    </Panel>
                    <Pagination meta={events.meta} />
                </>
            )}
        </>
    );
}
