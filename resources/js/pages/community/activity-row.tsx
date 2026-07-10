import { EntryRow } from '@/components/entry-row';
import { formatDate } from '@/lib/date';
import { useT } from '@/lib/i18n';

export interface CommunityActivityEntry {
    kind: 'topic' | 'event';
    id: number;
    name: string;
    commentCount: number;
    participantCount: number | null; // event roster size; null on topics (no roster)
    community: { id: number; name: string };
    updatedAt: string;
}

/** One row of the cross-community activity digest (dashboard + /community/recent). */
export function ActivityRow({ entry }: { entry: CommunityActivityEntry }) {
    const t = useT();
    return (
        <EntryRow
            href={entry.kind === 'topic' ? `/communityTopic/${entry.id}` : `/communityEvent/${entry.id}`}
            leading={
                <span className="shrink-0 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                    {entry.kind === 'topic' ? t('%Topic%') : t('Event')}
                </span>
            }
            title={entry.name}
            meta={[entry.community.name, formatDate(entry.updatedAt)]}
            commentCount={entry.commentCount}
            participantCount={entry.participantCount ?? 0}
        />
    );
}
