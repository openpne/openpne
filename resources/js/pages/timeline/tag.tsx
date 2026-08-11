import { Head, usePage } from '@inertiajs/react';
import { Pagination } from '@/components/pagination';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { TimelinePostCard } from './post-card';
import type { PaginatedTimelinePosts } from './types';

interface TagProps extends PageProps {
    tag: string;
    viewerId: number;
    posts: PaginatedTimelinePosts;
}

// One hashtag's posts. A reading page: the home feed's list with no compose box, since nothing here
// says which tag a new post would carry. The heading is the frame's (member-chrome.ts).
export default function TimelineTag() {
    const t = useT();
    const { tag, viewerId, posts } = usePage<TagProps>().props;

    return (
        <>
            <Head title={t('%Activity% posts tagged #:tag', { tag })} />
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
