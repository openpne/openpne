import { Head, usePage } from '@inertiajs/react';
import { Pagination } from '@/components/pagination';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { TimelinePostCard } from './post-card';
import type { PaginatedTimelinePosts, TimelinePostAuthor } from './types';

interface MemberProps extends PageProps {
    owner: TimelinePostAuthor;
    isOwner: boolean;
    viewerId: number;
    posts: PaginatedTimelinePosts;
}

export default function TimelineMember() {
    const t = useT();
    const { owner, isOwner, viewerId, posts } = usePage<MemberProps>().props;
    const title = isOwner ? t('%Activity%') : t(":name's %activity%", { name: owner.name });

    return (
        <>
            <Head title={title} />
            {posts.data.length === 0 ? (
                <Panel>
                    <p className="text-sm text-muted-foreground">{t('No %activity% posts to show.')}</p>
                </Panel>
            ) : (
                <>
                    <Panel flush>
                        <List>
                            {posts.data.map((post) => (
                                <TimelinePostCard key={post.id} post={post} viewerId={viewerId} />
                            ))}
                        </List>
                    </Panel>
                    <Pagination meta={posts.meta} />
                </>
            )}
        </>
    );
}
