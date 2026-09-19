import { Head, usePage } from '@inertiajs/react';
import { LoadOlder } from '@/components/load-older';
import { ReactorsDialog } from '@/components/reactions/reactors-dialog';
import { StreamEmpty, StreamHead } from '@/components/stream-empty';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { useReactions } from '@/lib/reactions/use-reactions';
import type { PageProps } from '@/types';
import { TimelinePostCard } from './post-card';
import { rowReactions, timelineReactionEndpoints } from './reactions';
import type { TimelineStream } from './types';

interface IndexProps extends PageProps {
    viewerId: number;
    posts: TimelineStream;
    streamGeneration: string;
    headUrl: string | null;
    /** What this site offers, as the page was rendered with it. */
    reactionVocabulary: string[];
}

export default function TimelineIndex() {
    const t = useT();
    const { viewerId, posts, streamGeneration, headUrl, reactionVocabulary } = usePage<IndexProps>().props;
    const reactions = useReactions(timelineReactionEndpoints, streamGeneration);
    const title = t('%Activity%');

    return (
        <>
            <Head title={title} />
            {posts.data.length === 0 ? (
                <StreamEmpty headUrl={headUrl} empty={t('No %activity% posts to show.')} older={t('No older posts.')} />
            ) : (
                <>
                    <StreamHead headUrl={headUrl} />
                    <LoadOlder data="posts" generation={streamGeneration}>
                        <Panel flush>
                            <List>
                                {posts.data.map((post) => (
                                    <TimelinePostCard key={post.id} post={post} viewerId={viewerId} reactions={rowReactions(post, reactionVocabulary, reactions)} />
                                ))}
                            </List>
                        </Panel>
                    </LoadOlder>
                </>
            )}
            {reactions.reactorsFor !== null && <ReactorsDialog url={reactions.reactorsUrl(reactions.reactorsFor)} onClose={reactions.closeReactors} />}
        </>
    );
}
