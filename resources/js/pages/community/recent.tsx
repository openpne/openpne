import { Head, usePage } from '@inertiajs/react';
import { List, ListRow, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface CommunityActivityEntry {
    kind: 'topic' | 'event';
    id: number;
    name: string;
    commentCount: number;
    community: { id: number; name: string };
    updatedAt: string;
}

interface RecentProps extends PageProps {
    activity: CommunityActivityEntry[];
}

/** The dashboard's community activity digest, expanded: recent topics and events across the
 *  viewer's joined communities, merged newest-first. */
export default function CommunityRecent() {
    const t = useT();
    const { activity } = usePage<RecentProps>().props;
    const title = t('Recent %community% activity');

    return (
        <>
            <Head title={title} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-xl font-semibold text-foreground">{title}</h1>

                {activity.length === 0 ? (
                    <Panel>
                        <p className="text-sm text-muted-foreground">{t('No activity to show yet.')}</p>
                    </Panel>
                ) : (
                    <Panel flush>
                        <List>
                            {activity.map((entry) => (
                                <ListRow
                                    key={`${entry.kind}-${entry.id}`}
                                    href={entry.kind === 'topic' ? `/m/community/topic/${entry.id}` : `/m/community/event/${entry.id}`}
                                    chevron
                                >
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
                            ))}
                        </List>
                    </Panel>
                )}
            </main>
        </>
    );
}
