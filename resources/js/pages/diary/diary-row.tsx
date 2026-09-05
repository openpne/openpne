import type { ReactNode } from 'react';
import { EntryRow } from '@/components/entry-row';
import { Timestamp } from '@/components/timestamp';
import type { DiarySummary } from './types';

/**
 * Always author-first: the byline names who wrote it even on a single-member archive. `rich` adds
 * the body excerpt and the photo strip; without it the has-photos marker still shows.
 */
export function DiaryRow({ diary, rich = false, actions }: { diary: DiarySummary; rich?: boolean; actions?: ReactNode }) {
    return (
        <EntryRow
            href={`/diary/${diary.id}`}
            author={diary.author}
            date={<Timestamp at={diary.createdAt} preset="listStamp" />}
            content={diary.title}
            commentCount={diary.commentCount}
            hasImages={diary.hasImages}
            excerpt={rich ? diary.excerpt : undefined}
            thumbnails={rich ? diary.thumbnails : undefined}
            actions={actions}
        />
    );
}
