import { EntryRow } from '@/components/entry-row';
import { CivilDate } from '@/components/timestamp';
import { BoardStamp, EventBoardStamp } from './board-stamp';
import { useT } from '@/lib/i18n';

export interface CommunityActivityEntry {
    kind: 'topic' | 'event';
    id: number;
    name: string;
    commentCount: number;
    participantCount: number | null; // event roster size; null on topics (no roster)
    openDate: string | null; // Y-m-d on events; null on topics
    group: { id: number; name: string; imageUrl: string | null };
    bumpedAt: string;
}

/** The group, not a member, is the byline subject: bumped_at is the last commenter's instant when
 *  there is a comment, so an author byline would misattribute the row. */
export function ActivityRow({ entry }: { entry: CommunityActivityEntry }) {
    const t = useT();
    return (
        <EntryRow
            href={entry.kind === 'topic' ? `/topics/${entry.id}` : `/events/${entry.id}`}
            group={entry.group}
            content={entry.name}
            // An event's open date names its kind; a note and a weekday beside it would push the
            // group name out of a phone-wide byline.
            bylineNote={entry.kind === 'topic' ? t('%Topic%') : undefined}
            date={
                entry.openDate === null ? (
                    <BoardStamp commentCount={entry.commentCount} bumpedAt={entry.bumpedAt} />
                ) : (
                    <>
                        {t('Open date')}: <CivilDate value={entry.openDate} />
                        <EventBoardStamp commentCount={entry.commentCount} bumpedAt={entry.bumpedAt} />
                    </>
                )
            }
            commentCount={entry.commentCount}
            participantCount={entry.participantCount ?? 0}
        />
    );
}
