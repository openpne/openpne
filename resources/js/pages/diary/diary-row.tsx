import type { ReactNode } from 'react';
import { EntryRow } from '@/components/entry-row';
import { useDateFormat } from '@/lib/use-date-format';
import type { DiarySummary } from './types';

/**
 * The canonical diary list row (feed, personal archive, dashboard digests). Always author-first: the
 * byline names who wrote it even on a single-member archive. `rich` adds the body excerpt and the
 * photo strip for the browsable feed/archive lists; the compact dashboard digests leave it off (the
 * has-photos marker still shows via `hasImages`).
 */
export function DiaryRow({ diary, rich = false, actions }: { diary: DiarySummary; rich?: boolean; actions?: ReactNode }) {
    const date = useDateFormat();
    return (
        <EntryRow
            href={`/diary/${diary.id}`}
            author={diary.author}
            date={date.instantDate(diary.createdAt)}
            content={diary.title}
            commentCount={diary.commentCount}
            hasImages={diary.hasImages}
            excerpt={rich ? diary.excerpt : undefined}
            thumbnails={rich ? diary.thumbnails : undefined}
            actions={actions}
        />
    );
}
