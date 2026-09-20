import { Head, usePage } from '@inertiajs/react';
import { LoadOlder } from '@/components/load-older';
import { ReactorsDialog } from '@/components/reactions/reactors-dialog';
import { StreamEmpty, StreamHead } from '@/components/stream-empty';
import { useT } from '@/lib/i18n';
import { useReactions } from '@/lib/reactions/use-reactions';
import type { PageProps } from '@/types';
import { TimelineFeedList } from './feed-list';
import { timelineReactionEndpoints } from './reactions';
import type { TimelineStream } from './types';

interface TagProps extends PageProps {
    tag: string;
    viewerId: number;
    posts: TimelineStream;
    streamGeneration: string;
    headUrl: string | null;
    reactionVocabulary: string[];
}

// A reading page: the home feed's list with no compose box, since nothing here says which tag a new
// post would carry.
export default function TimelineTag() {
    const t = useT();
    const { tag, viewerId, posts, streamGeneration, headUrl, reactionVocabulary } = usePage<TagProps>().props;
    const reactions = useReactions(timelineReactionEndpoints, streamGeneration);

    return (
        <>
            <Head title={t('%Activity% posts tagged #:tag', { tag })} />
            {posts.data.length === 0 ? (
                <StreamEmpty headUrl={headUrl} empty={t('No %activity% posts to show.')} older={t('No older posts.')} />
            ) : (
                <>
                    <StreamHead headUrl={headUrl} />
                    <LoadOlder data="posts" generation={streamGeneration}>
                        <TimelineFeedList posts={posts.data} viewerId={viewerId} reactions={reactions} reactionVocabulary={reactionVocabulary} />
                    </LoadOlder>
                </>
            )}
            {reactions.reactorsFor !== null && <ReactorsDialog url={reactions.reactorsUrl(reactions.reactorsFor)} emoji={reactions.reactorsEmoji} returnFocusTo={reactions.reactorsOpener} onClose={reactions.closeReactors} />}
        </>
    );
}
