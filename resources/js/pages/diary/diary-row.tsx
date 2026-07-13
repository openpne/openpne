import type { ReactNode } from 'react';
import { Avatar } from '@/components/avatar';
import { EntryRow } from '@/components/entry-row';
import { formatDate } from '@/lib/date';
import type { DiarySummary } from './types';

/**
 * The canonical diary list row (feed, personal archive, dashboard digests). `showAuthor` is off on
 * single-member archives where the author would repeat on every row. `rich` adds the body excerpt and
 * a thumbnail for the browsable feed/archive lists; the compact dashboard digests leave it off.
 */
export function DiaryRow({ diary, showAuthor = false, rich = false, actions }: { diary: DiarySummary; showAuthor?: boolean; rich?: boolean; actions?: ReactNode }) {
    return (
        <EntryRow
            href={`/diary/${diary.id}`}
            leading={showAuthor ? <Avatar id={diary.author.id} name={diary.author.name} src={diary.author.imageUrl} color={diary.author.avatarColor} size="sm" decorative /> : undefined}
            title={diary.title}
            meta={[showAuthor && diary.author.name, formatDate(diary.createdAt)]}
            commentCount={diary.commentCount}
            hasImages={diary.hasImages}
            excerpt={rich ? diary.excerpt : undefined}
            thumbnail={rich ? (diary.thumbnailUrl ?? undefined) : undefined}
            actions={actions}
        />
    );
}
