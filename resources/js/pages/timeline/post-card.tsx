import { Link } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { DangerLink } from '@/components/ui/danger-link';
import { formatDateTime } from '@/lib/date';
import { useT } from '@/lib/i18n';
import type { TimelinePostEntry } from './types';

interface TimelinePostCardProps {
    post: TimelinePostEntry;
    viewerId: number;
}

// A single timeline post card, shared by the member timeline and the home feed. The delete control
// shows only on the viewer's own posts.
export function TimelinePostCard({ post, viewerId }: TimelinePostCardProps) {
    const t = useT();
    const isOwn = post.author.id === viewerId;

    return (
        <li className="space-y-2 px-5 py-4 text-foreground">
            <div className="flex items-center justify-between gap-3 text-sm">
                <Link href={`/m/member/${post.author.id}/timeline`} className="flex min-w-0 items-center gap-2 font-medium text-link hover:underline">
                    <Avatar id={post.author.id} name={post.author.name} src={post.author.imageUrl} size="sm" decorative />
                    <span className="truncate">{post.author.name}</span>
                </Link>
                <Link href={`/m/timeline/${post.id}`} className="shrink-0 text-muted-foreground hover:text-foreground hover:underline">
                    {formatDateTime(post.createdAt)}
                </Link>
            </div>
            <p className="whitespace-pre-wrap break-words">{post.body}</p>
            {post.images.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {post.images.map((image) => (
                        <img key={image.id} src={image.thumbnailUrl} alt="" className="rounded-md" />
                    ))}
                </div>
            )}
            {isOwn && (
                <DangerLink href={`/m/timeline/deleteConfirm/${post.id}`} className="text-sm">
                    {t('Delete')}
                </DangerLink>
            )}
        </li>
    );
}
