import { EntryRow } from '@/components/entry-row';
import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';

export interface CommunityActivityEntry {
    kind: 'topic' | 'event';
    id: number;
    name: string;
    commentCount: number;
    participantCount: number | null; // event roster size; null on topics (no roster)
    community: { id: number; name: string; imageUrl: string | null };
    updatedAt: string;
}

/** One row of the cross-community activity digest (dashboard + /community/recent). The community —
 *  not a member — is the byline subject: updated_at bumps on any comment, so an author byline would
 *  misattribute the row. */
export function ActivityRow({ entry }: { entry: CommunityActivityEntry }) {
    const t = useT();
    const date = useDateFormat();
    return (
        <EntryRow
            href={entry.kind === 'topic' ? `/communityTopic/${entry.id}` : `/communityEvent/${entry.id}`}
            community={entry.community}
            content={entry.name}
            bylineNote={entry.kind === 'topic' ? t('%Topic%') : t('Event')}
            date={date.instantDate(entry.updatedAt)}
            commentCount={entry.commentCount}
            participantCount={entry.participantCount ?? 0}
        />
    );
}
