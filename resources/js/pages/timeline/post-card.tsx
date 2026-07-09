import { Link, router } from '@inertiajs/react';
import { MessageCircle } from 'lucide-react';
import { ImageGrid } from '@/components/image-grid';
import { Avatar } from '@/components/avatar';
import { useConfirm } from '@/components/confirm-dialog';
import { CountBadge } from '@/components/entry-row';
import { UserText } from '@/components/user-text';
import { dangerActionClass } from '@/components/ui/danger-link';
import { formatDateTime } from '@/lib/date';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { TimelinePostEntry } from './types';

interface TimelinePostCardProps {
    post: TimelinePostEntry;
    viewerId: number;
}

// A single timeline post card, shared by the member timeline and the home feed. The delete control
// shows only on the viewer's own posts.
export function TimelinePostCard({ post, viewerId }: TimelinePostCardProps) {
    const t = useT();
    const confirm = useConfirm();
    const isOwn = post.author.id === viewerId;

    const deletePost = async () => {
        if (await confirm({ title: t('Delete this post?'), confirmLabel: t('Delete'), danger: true })) {
            router.post(`/m/timeline/delete/${post.id}`);
        }
    };

    return (
        <li className="space-y-2 px-5 py-4 text-foreground">
            <div className="flex items-center justify-between gap-3 text-sm">
                <Link href={`/m/member/${post.author.id}/timeline`} className="flex min-w-0 items-center gap-2 font-medium text-link hover:underline">
                    <Avatar id={post.author.id} name={post.author.name} src={post.author.imageUrl} size="sm" decorative />
                    <span className="truncate">{post.author.name}</span>
                </Link>
                <div className="flex shrink-0 items-center gap-2 text-muted-foreground">
                    <CountBadge icon={MessageCircle} count={post.replyCount} srLabel={t(':count replies', { count: post.replyCount })} />
                    <Link href={`/m/timeline/${post.id}`} className="hover:text-foreground hover:underline">
                        {formatDateTime(post.createdAt)}
                    </Link>
                </div>
            </div>
            <p className="whitespace-pre-wrap break-words">
                <UserText text={post.body} />
            </p>
            <ImageGrid images={post.images} />
            {isOwn && (
                <button type="button" onClick={deletePost} className={cn(dangerActionClass, 'text-sm')}>
                    {t('Delete')}
                </button>
            )}
        </li>
    );
}
