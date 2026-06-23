import { Link } from '@inertiajs/react';
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
        <li className="space-y-2 border-b pb-4">
            <div className="flex items-center justify-between text-sm">
                <Link href={`/m/member/${post.author.id}/timeline`} className="font-medium hover:underline">
                    {post.author.name}
                </Link>
                <Link href={`/m/timeline/${post.id}`} className="text-muted-foreground hover:underline">
                    {new Date(post.createdAt).toLocaleString()}
                </Link>
            </div>
            <p className="whitespace-pre-wrap">{post.body}</p>
            {post.images.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {post.images.map((image) => (
                        <img key={image.id} src={image.thumbnailUrl} alt="" className="rounded" />
                    ))}
                </div>
            )}
            {isOwn && (
                <Link href={`/m/timeline/deleteConfirm/${post.id}`} className="text-sm hover:underline">
                    {t('Delete')}
                </Link>
            )}
        </li>
    );
}
