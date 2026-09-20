import { Head, usePage } from '@inertiajs/react';
import { LoadOlder } from '@/components/load-older';
import { ReactorsDialog } from '@/components/reactions/reactors-dialog';
import { StreamEmpty, StreamHead } from '@/components/stream-empty';
import { useT } from '@/lib/i18n';
import { useReactions } from '@/lib/reactions/use-reactions';
import type { PageProps } from '@/types';
import { TimelineFeedList } from './feed-list';
import { timelineReactionEndpoints } from './reactions';
import type { TimelineStream, TimelinePostAuthor } from './types';

interface MemberProps extends PageProps {
    owner: TimelinePostAuthor;
    isOwner: boolean;
    viewerId: number;
    posts: TimelineStream;
    streamGeneration: string;
    headUrl: string | null;
    reactionVocabulary: string[];
}

export default function TimelineMember() {
    const t = useT();
    const { owner, isOwner, viewerId, posts, streamGeneration, headUrl, reactionVocabulary } = usePage<MemberProps>().props;
    const reactions = useReactions(timelineReactionEndpoints, streamGeneration);
    const title = isOwner ? t('%Activity%') : t(":name's %activity%", { name: owner.name });

    return (
        <>
            <Head title={title} />
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
