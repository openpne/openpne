import { Head, usePage } from '@inertiajs/react';
import { Pagination } from '@/components/pagination';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { TimelinePostCard } from './post-card';
import type { PaginatedTimelinePosts } from './types';

interface CommunityProps extends PageProps {
    group: { id: number; name: string };
    viewerId: number;
    canPost: boolean;
    posts: PaginatedTimelinePosts;
}

export default function TimelineCommunity() {
    const t = useT();
    // canPost is read by the chrome, which carries the compose action for every page that has one.
    const { group, viewerId, posts } = usePage<CommunityProps>().props;
    const title = t(':community %activity%', { group: group.name });

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
