import { Head, usePage } from '@inertiajs/react';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { ActivityRow, type CommunityActivityEntry } from './activity-row';
import { CommunityTabs } from './community-tabs';

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
                <h1 className="break-words text-xl font-semibold text-foreground">{title}</h1>

                <CommunityTabs active="recent" />

                {activity.length === 0 ? (
                    <Panel>
                        <p className="text-sm text-muted-foreground">{t('No activity to show yet.')}</p>
                    </Panel>
                ) : (
                    <Panel flush>
                        <List>
                            {activity.map((entry) => (
                                <ActivityRow key={`${entry.kind}-${entry.id}`} entry={entry} />
                            ))}
                        </List>
                    </Panel>
                )}
            </main>
        </>
    );
}
