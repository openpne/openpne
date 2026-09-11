import { Head, usePage } from '@inertiajs/react';
import { LoadOlder } from '@/components/load-older';
import { StreamEmpty } from '@/components/stream-empty';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { TimelinePostCard } from './post-card';
import type { TimelineStream } from './types';

interface IndexProps extends PageProps {
    viewerId: number;
    posts: TimelineStream;
    streamGeneration: string;
    headUrl: string | null;
}

export default function TimelineIndex() {
    const t = useT();
    const { viewerId, posts, streamGeneration, headUrl } = usePage<IndexProps>().props;
    const title = t('%Activity%');

    return (
        <>
            <Head title={title} />
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
