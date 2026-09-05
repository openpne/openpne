import { EntryRow } from '@/components/entry-row';
import { Timestamp } from '@/components/timestamp';
import type { TimelinePostEntry } from './types';

export function TimelineRow({ post }: { post: TimelinePostEntry }) {
    return (
        <EntryRow
            href={`/timeline/${post.id}`}
            author={post.author}
            content={post.body}
            contentLines={2}
            date={<Timestamp at={post.createdAt} preset="relative" />}
            replyCount={post.replyCount}
        />
    );
}
