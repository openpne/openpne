import { Head, usePage } from '@inertiajs/react';
import { LoadOlder } from '@/components/load-older';
import { StreamEmpty } from '@/components/stream-empty';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { TimelinePostCard } from './post-card';
import type { TimelineStream } from './types';

interface TagProps extends PageProps {
    tag: string;
    viewerId: number;
    posts: TimelineStream;
    streamGeneration: string;
    headUrl: string | null;
}

// A reading page: the home feed's list with no compose box, since nothing here says which tag a new
// post would carry.
export default function TimelineTag() {
    const t = useT();
    const { tag, viewerId, posts, streamGeneration, headUrl } = usePage<TagProps>().props;

    return (
        <>
            <Head title={t('%Activity% posts tagged #:tag', { tag })} />
            {posts.data.length === 0 ? (
                <StreamEmpty headUrl={headUrl} empty={t('No %activity% posts to show.')} older={t('No older posts.')} />
            ) : (
                <LoadOlder data="posts" generation={streamGeneration}>
                    <Panel flush>
                        <List>
                            {posts.data.map((post) => (
                                <TimelinePostCard key={post.id} post={post} viewerId={viewerId} />
                            ))}
                        </List>
                    </Panel>
                </LoadOlder>
            )}
        </>
    );
}
